<?php

namespace App\Services\Exams;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A student list uploaded to an exam or an exam category (CSV, Excel .xlsx, or plain text with one
 * matric number or e-mail per line). Headings are recognised the way the old exam system did
 * (firstname / middlename / lastname or name, email, matric_no or reg_no, password, phone).
 *
 * read() keeps the list for an hour and returns a preview: who already has an account, who will be
 * created, and the lines that cannot be used. Nothing is created until the list is assigned.
 */
class StudentListImport
{
    private const ALIASES = [
        'firstname' => ['firstname', 'first_name', 'fname', 'given_name', 'givenname', 'forename', 'first'],
        'middlename' => ['middlename', 'middle_name', 'mname', 'othername', 'other_name', 'othernames', 'other_names', 'middle'],
        'lastname' => ['lastname', 'last_name', 'lname', 'surname', 'family_name', 'familyname'],
        'name' => ['name', 'fullname', 'full_name', 'full_names', 'student_name', 'studentname', 'names'],
        'email' => ['email', 'email_address', 'e_mail', 'mail'],
        'identifier' => ['matric_no', 'matric', 'matric_number', 'matriculation_number', 'mat_no', 'matno', 'reg_no', 'regno', 'reg_number', 'registration_number',
            'admission_no', 'admission_number', 'identifier', 'student_id', 'student_no', 'staff_no', 'staff_id', 'staff_number', 'employee_no', 'employee_id', 'pf_no', 'file_no', 'jamb_no', 'jamb_reg_no', 'exam_no', 'candidate_no', 'username', 'user_name'],
        'password' => ['password', 'pass', 'pwd'],
        'phone' => ['phone', 'phone_number', 'mobile', 'gsm', 'telephone'],
    ];

    /** @return array{token: string, total: int, existing: int, new: int, invalid: list<array{line:int, reason:string}>, sample: list<array>} */
    public function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $table = $ext === 'xlsx' ? self::xlsxRows($file->getRealPath()) : self::csvRows(\App\Services\Questions\Import\DocumentConverter::toUtf8((string) file_get_contents($file->getRealPath())));
        $rows = $this->rows($table);

        $token = Str::random(32);
        Cache::put($this->key($token), $rows, now()->addHour());

        return ['token' => $token] + $this->summary($rows);
    }

    /** People on the list: existing accounts, plus new student accounts when asked. @return array{0: Collection<int,User>, 1: list<string>} */
    public function users(string $token, bool $create, string $passwordRule = 'file_or_matric', string $audience = 'student'): array
    {
        $rows = Cache::get($this->key($token));
        abort_if($rows === null, 422, 'The uploaded list has expired. Upload it again.');
        $users = collect();
        $missing = [];
        if ($create) {
            // new student accounts count towards the subscription's student limit
            $new = count(array_filter($rows, fn ($r) => ! $r['error'] && ! $this->find($r)));
            $new && $audience === 'student' && app(\App\Services\Billing\Entitlements::class)->check('students', $new, tenant());
        }
        DB::transaction(function () use ($rows, $create, $passwordRule, $audience, &$users, &$missing) {
            foreach ($rows as $r) {
                if ($r['error']) {
                    $missing[] = "line {$r['line']}";

                    continue;
                }
                $user = $this->find($r);
                if (! $user && $create) {
                    $user = User::forceCreate([
                        'name' => $r['name'] ?: ($r['identifier'] ?? $r['email']),
                        'email' => $r['email'] ?: Str::slug((string) $r['identifier'], '.').'.'.Str::lower(Str::random(6)).'@'.($audience === 'student' ? 'students' : 'staff').'.invalid',
                        'identifier' => $r['identifier'] ?: null,
                        'phone' => $r['phone'] ?: null,
                        'password' => Hash::make(match ($passwordRule) {
                            'matric' => (string) ($r['identifier'] ?: $r['email']),
                            'surname' => Str::lower($r['surname'] ?: ($r['identifier'] ?: Str::random(12))),
                            default => $r['password'] ?: (string) ($r['identifier'] ?: $r['email']),
                        }),
                        'user_type' => $audience, 'status' => 'active', 'tenant_id' => tenant_id(),
                    ]);
                    // a staff member made for a promotion exam gets no staff role: only the exam
                    $audience === 'student' && $user->syncRoles(['student']);
                }
                $user ? $users->push($user) : $missing[] = $r['identifier'] ?: $r['email'];
            }
        });

        return [$users->unique('id')->values(), $missing];
    }

    public function summary(array $rows): array
    {
        $valid = array_values(array_filter($rows, fn ($r) => ! $r['error']));
        $idents = array_values(array_filter(array_column($valid, 'identifier')));
        $emails = array_values(array_filter(array_column($valid, 'email')));
        $known = User::where(fn ($q) => $q->whereIn('identifier', $idents ?: ['-'])->orWhereIn('email', $emails ?: ['-']))->get(['id', 'identifier', 'email', 'name', 'user_type']);
        $byId = $known->keyBy(fn ($u) => Str::lower((string) $u->identifier));
        $byMail = $known->keyBy(fn ($u) => Str::lower($u->email));
        $existing = 0;
        $sample = [];
        foreach ($valid as $r) {
            $u = ($r['identifier'] ? $byId[Str::lower($r['identifier'])] ?? null : null) ?? ($r['email'] ? $byMail[Str::lower($r['email'])] ?? null : null);
            $u && $existing++;
            count($sample) < 15 && $sample[] = ['line' => $r['line'], 'name' => $u?->name ?? $r['name'], 'identifier' => $r['identifier'], 'email' => $r['email'], 'has_account' => (bool) $u];
        }

        return ['total' => count($valid), 'existing' => $existing, 'new' => count($valid) - $existing,
            'invalid' => array_map(fn ($r) => ['line' => $r['line'], 'reason' => $r['error']], array_values(array_filter($rows, fn ($r) => $r['error']))),
            'sample' => $sample];
    }

    private function find(array $r): ?User
    {
        return User::where(function ($q) use ($r) {
            $r['identifier'] && $q->orWhere('identifier', $r['identifier']);
            $r['email'] && $q->orWhere('email', $r['email']);
        })->first();
    }

    private function key(string $token): string
    {
        return 'student-list:'.tenant_id().':'.$token;
    }

    /** @param list<list<string>> $table */
    private function rows(array $table): array
    {
        $table = array_values(array_filter($table, fn ($r) => array_filter($r, fn ($c) => trim((string) $c) !== '')));
        if (! $table) {
            return [];
        }
        $map = $this->headings($table[0]);
        $start = $map ? 1 : 0;
        $out = [];
        foreach (array_slice($table, $start, 20000, true) as $i => $row) {
            $get = fn (string $k) => isset($map[$k]) ? self::clean($row[$map[$k]] ?? '') : '';
            if (! $map) {
                // no headings: a matric number or e-mail per line (anything after a comma is the name)
                $first = self::clean($row[0] ?? '');
                $r = ['identifier' => str_contains($first, '@') ? '' : $first, 'email' => str_contains($first, '@') ? Str::lower($first) : '', 'name' => self::clean($row[1] ?? ''), 'surname' => '', 'password' => '', 'phone' => ''];
            } else {
                $last = $get('lastname');
                $name = $get('name') ?: trim(preg_replace('/\s+/', ' ', $get('firstname').' '.$get('middlename').' '.$last));
                $r = ['identifier' => $get('identifier'), 'email' => Str::lower($get('email')), 'name' => mb_substr($name, 0, 190), 'surname' => $last ?: (Str::of($name)->explode(' ')->first() ?? ''),
                    'password' => $get('password'), 'phone' => $get('phone')];
            }
            $r['line'] = $i + 1;
            $r['error'] = match (true) {
                $r['identifier'] === '' && $r['email'] === '' => 'no matric number or e-mail',
                $r['email'] !== '' && ! filter_var($r['email'], FILTER_VALIDATE_EMAIL) => "\"{$r['email']}\" is not an e-mail address",
                mb_strlen($r['identifier']) > 60 => 'the matric number is too long',
                default => null,
            };
            $out[] = $r;
        }

        return $out;
    }

    private function headings(array $row): array
    {
        $map = [];
        foreach ($row as $i => $h) {
            $k = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(self::clean($h))), '_');
            foreach (self::ALIASES as $field => $names) {
                if (! isset($map[$field]) && in_array($k, $names, true)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }

        return isset($map['identifier']) || isset($map['email']) ? $map : [];
    }

    private static function clean(mixed $v): string
    {
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', preg_replace('/^\xEF\xBB\xBF/', '', (string) $v)));

        return in_array(strtoupper($v), ['NULL', 'N/A', 'NA', 'NIL', 'NONE', '-', '--', 'UNKNOWN'], true) ? '' : $v;
    }

    /** @return list<list<string>> */
    public static function csvRows(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $first = strtok($text, "\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $text);
        rewind($fh);
        $rows = [];
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($c) => (string) $c, $row);
        }
        fclose($fh);

        return $rows;
    }

    /** The first sheet of an Excel .xlsx file, as rows of text (no extra library needed). @return list<list<string>> */
    public static function xlsxRows(string $path): array
    {
        $zip = new \ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'That Excel file could not be opened. Save it again as .xlsx or CSV.');
        $strings = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = simplexml_load_string($xml);
            foreach ($doc->si as $si) {
                $strings[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            foreach (range(0, $zip->numFiles - 1) as $i) {
                $n = $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) {
                    $sheet = $zip->getFromName($n);
                    break;
                }
            }
        }
        $zip->close();
        abort_if($sheet === false, 422, 'That Excel file has no sheet.');
        $doc = simplexml_load_string($sheet);
        $rows = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = 0;
                foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $type = (string) $c['t'];
                $v = match ($type) {
                    's' => $strings[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => (string) $c->v,
                };
                // whole numbers stored as numbers (e.g. phone numbers) without ".0"
                if ($type === '' && is_numeric($v) && floor((float) $v) == (float) $v && abs((float) $v) < 1e15) {
                    $v = number_format((float) $v, 0, '', '');
                }
                $cells[max(0, $col - 1)] = $v;
            }
            if ($cells) {
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $rows[] = $line;
            }
        }

        return $rows;
    }
}
