<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Services\Questions\Import\DocumentConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checks against real programs when they are installed on the machine running the tests
 * (skipped otherwise): Pandoc for the standard Word reader, and an SMTP server for e-mail.
 *   PANDOC_PATH=/path/to/pandoc            (or pandoc on the PATH)
 *   TEST_SMTP_HOST=127.0.0.1 TEST_SMTP_PORT=1025   (any SMTP server that accepts mail, e.g. Mailpit)
 */
class RealServicesTest extends TestCase
{
    use \Tests\Feature\Exams\BuildsExams, RefreshDatabase;

    public function test_real_pandoc_and_the_science_reader_agree_on_word_equations(): void
    {
        $pandoc = getenv('PANDOC_PATH') ?: 'pandoc';
        $converter = new DocumentConverter($pandoc);
        if (! $converter->pandocAvailable()) {
            $this->markTestSkipped('Pandoc is not installed here.');
        }
        $dir = sys_get_temp_dir().'/vt-pandoc-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/q.md", "1. Simplify \$\\frac{x^{2}+1}{\\sqrt{y}}\$ and \$\\sum_{i=1}^{n} i^2\$.\n");
        $p = new \Symfony\Component\Process\Process([$pandoc, "$dir/q.md", '-o', "$dir/q.docx"]);
        $p->run();
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput()); // a real Word file with Word equations (OMML)

        $standard = $converter->toHtml("$dir/q.docx", 'docx', "$dir/m1")['html'];
        $science = $converter->toHtml("$dir/q.docx", 'sci-docx', "$dir/m2")['html'];
        $this->assertStringContainsString('\frac{x^{2}', $standard);
        $this->assertStringContainsString('\frac{x^{2}+1}{\sqrt{y}}', $science);
        $this->assertStringContainsString('_{i=1}^{n}', $science);
    }

    public function test_mail_reaches_a_real_smtp_server(): void
    {
        $host = getenv('TEST_SMTP_HOST');
        $port = (int) getenv('TEST_SMTP_PORT');
        if (! $host || ! $port || ! @fsockopen($host, $port, $errno, $errstr, 1)) {
            $this->markTestSkipped('No SMTP server given (TEST_SMTP_HOST / TEST_SMTP_PORT).');
        }
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => $host, 'mail.mailers.smtp.port' => $port, 'mail.mailers.smtp.encryption' => null, 'mail.mailers.smtp.scheme' => null]);
        $this->setUpPlatform();
        $admin = $this->officer;

        $this->actingAs($admin)->post('/admin/integrations/mail/test')->assertSessionHas('success');
        $admin->notifyNow(new \App\Notifications\LmsNotice('Real mail check', 'Body', '/dashboard'), ['mail']);
        $this->assertTrue(true); // the SMTP server accepted both (an exception fails the test)
    }
}
