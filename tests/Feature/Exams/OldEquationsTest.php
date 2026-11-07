<?php

namespace Tests\Feature\Exams;

use App\Services\Questions\Import\CompoundFile;
use App\Services\Questions\Import\MtefToLatex;
use App\Services\Questions\Import\WordScienceReader;
use Tests\Support\MtefFixture as F;
use Tests\TestCase;

/**
 * Equation 3.0 and MathType equations (embedded OLE objects in Word) become LaTeX in the
 * science & maths reader. Typefaces: 1 text, 2 function, 3 variable, 4/5 Greek, 6 symbol, 7 vector, 8 number.
 */
class OldEquationsTest extends TestCase
{
    private function latex(string $mtef): string
    {
        return (new MtefToLatex)->fromOle(F::ole(F::equationNative($mtef)));
    }

    public function test_equation_3_fractions_roots_scripts_and_greek(): void
    {
        $eq = F::v3(F::line3(F::char3(3, 'x').F::tmpl3(28, 0, F::nullLine3().F::line3(F::char3(8, '2'))).F::char3(6, '+')
            .F::tmpl3(10, 0, F::line3(F::tmpl3(11, 0, F::line3(F::char3(3, 'a')).F::line3(F::char3(3, 'b')))).F::nullLine3())
            .F::char3(6, '=').F::char3(4, 0x61).F::char3(6, 0xB4).F::char3(5, 0x44)));
        $this->assertSame('x^{2}+\sqrt{\frac{a}{b}}=α×Δ', $this->latex($eq));
    }

    public function test_equation_3_sums_functions_cube_roots_and_chemistry(): void
    {
        $sum = F::tmpl3(16, 0, F::line3(F::char3(3, 'i')).F::line3(F::chars3(3, 'i').F::char3(6, '=').F::char3(8, '1')).F::line3(F::char3(3, 'n')).F::char3(6, 0x2211));
        $this->assertSame('\sum_{i=1}^{n} i', $this->latex(F::v3(F::line3($sum))));

        $this->assertSame('\sin x+\sqrt[3]{y}', $this->latex(F::v3(F::line3(F::chars3(2, 'sin').F::char3(3, 'x').F::char3(6, '+')
            .F::tmpl3(10, 1, F::line3(F::char3(3, 'y')).F::line3(F::char3(8, '3')))))));

        // H2SO4 → 2H+ + SO4^2- with an equilibrium arrow
        $chem = F::chars3(3, 'H').F::tmpl3(27, 0, F::line3(F::char3(8, '2')).F::nullLine3()).F::chars3(3, 'SO').F::tmpl3(27, 0, F::line3(F::char3(8, '4')).F::nullLine3())
            .F::tmpl3(14, 3, F::line3(F::chars3(1, 'aq')).F::nullLine3()).F::chars3(3, 'H').F::tmpl3(28, 0, F::nullLine3().F::line3(F::char3(6, '+')));
        $this->assertSame('H_{2}SO_{4}\overset{\text{aq}}{\rightleftharpoons}H^{+}', $this->latex(F::v3(F::line3($chem))));

        $this->assertSame('\left( a+b \right)', $this->latex(F::v3(F::line3(F::tmpl3(1, 3, F::line3(F::chars3(3, 'a').F::char3(6, '+').F::char3(3, 'b')).F::char3(6, '(').F::char3(6, ')'))))));
        $this->assertSame('\int_{0}^{1} xdx', $this->latex(F::v3(F::line3(F::tmpl3(15, 1, F::line3(F::chars3(3, 'x').F::chars3(3, 'dx')).F::line3(F::char3(8, '0')).F::line3(F::char3(8, '1')).F::char3(6, 0x222B))))));
    }

    public function test_mathtype_5_with_definitions_accents_matrices_and_piles(): void
    {
        $dot = F::char5(3, 'x', F::embell5(2));
        $frac = F::tmpl5(11, 0, F::line5($dot).F::line5(F::char5(8, '2')));
        $this->assertSame('\frac{\dot{x}}{2}', $this->latex(F::v5(F::line5($frac))));

        $m = F::matrix5(2, 2, F::line5(F::char5(8, '1')).F::line5(F::char5(8, '0')).F::line5(F::char5(8, '0')).F::line5(F::char5(8, '1')));
        $this->assertSame('\left[ \begin{matrix} 1 & 0 \\\\ 0 & 1 \end{matrix} \right]', $this->latex(F::v5(F::line5(F::tmpl5(3, 3, F::line5($m).F::char5(6, '[').F::char5(6, ']'))))));

        $pile = F::pile5(F::line5(F::chars5(3, 'a').F::char5(6, '=').F::char5(8, '1')).F::line5(F::chars5(3, 'b').F::char5(6, '=').F::char5(8, '2')));
        $this->assertSame('\begin{array}{l} a=1 \\\\ b=2 \end{array}', $this->latex(F::v5($pile)));

        $this->assertSame("\\vec{F}=m\\vec{a}+α'", $this->latex(F::v5(F::line5(F::char5(3, 'F', F::embell5(11)).F::char5(6, '=').F::char5(3, 'm').F::char5(3, 'a', F::embell5(11)).F::char5(6, '+').F::char5(4, 0x03B1, F::embell5(5))))));
    }

    public function test_a_word_file_with_an_old_equation_is_read_into_maths(): void
    {
        $eq = F::v3(F::line3(F::char3(3, 'x').F::tmpl3(28, 0, F::nullLine3().F::line3(F::char3(8, '2'))).F::char3(6, '<').F::char3(8, '4')));
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, F::docx('1. Solve ', F::ole(F::equationNative($eq))));
        $html = (new WordScienceReader)->read($path, sys_get_temp_dir().'/vt-media-'.uniqid())['html'];
        $this->assertStringContainsString('1. Solve <span class="math inline">\(x^{2}&lt;4\)</span>', $html);
        $this->assertStringNotContainsString('could not be read', $html);

        // MathType's ProgID too, and a broken object falls back to a clear message instead of failing the upload
        file_put_contents($path, F::docx('2. ', 'not an ole file', 'Equation.DSMT4'));
        $this->assertStringContainsString('old-style equation that could not be read', (new WordScienceReader)->read($path, sys_get_temp_dir().'/vt-media-'.uniqid())['html']);
        @unlink($path);
    }

    public function test_damaged_or_hostile_data_is_refused_cleanly(): void
    {
        $this->expectException(\RuntimeException::class);
        new CompoundFile(str_repeat("\0", 600));
    }

    public function test_truncated_and_looping_equations_do_not_hang(): void
    {
        $ok = F::v3(F::line3(F::char3(3, 'x')));
        foreach ([substr(F::v3(F::line3(F::tmpl3(11, 0, F::line3(F::char3(3, 'a'))))), 0, 12), "\x09".substr($ok, 1), str_repeat("\x03\x01\x00\x00", 200)] as $bad) {
            try {
                (new MtefToLatex)->fromMtef($bad);
                $this->assertTrue(true);
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        // and a good one still reads after the bad ones
        $ole = F::ole(F::equationNative($ok));
        $this->assertSame('x', (new MtefToLatex)->fromOle($ole));
    }
}
