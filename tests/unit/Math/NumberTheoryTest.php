<?php
declare(strict_types=1);

namespace Mdanter\Ecc\Tests\Math;

use Mdanter\Ecc\Math\GmpMath;
use Mdanter\Ecc\Math\GmpMathInterface;
use Mdanter\Ecc\Math\NumberTheory;

class NumberTheoryTest extends NumberTheoryTestBase
{
    /**
     * @var
     */
    protected $compression_data;

    /**
     * @var
     */
    protected $sqrt_data;

    /**
     * @var \Mdanter\Ecc\Primitives\GeneratorPoint
     */
    protected $generator;

    public function getInvalidRootsProvider(): array
    {
        $file_sqrt = TEST_DATA_DIR.'/square_root_mod_p.json';
        $sqrt_data = json_decode(file_get_contents($file_sqrt));
        $fixtures = [];
        foreach ($sqrt_data->no_root as $r) {
            $fixtures[] = [(string) $r->a, (string) $r->p];
        }
        return $fixtures;
    }

    /**
     * @dataProvider getInvalidRootsProvider
     * @param string $a
     * @param string $p
     */
    public function testSqrtDataWithNoRoots(string $a, string $p)
    {
        $this->expectException(\Mdanter\Ecc\Exception\SquareRootException::class);
        $adapter = new GmpMath();
        $theory = $adapter->getNumberTheory();
        $theory->squareRootModP(gmp_init($a, 10), gmp_init($p, 10));
    }

    /**
     * @dataProvider getAdapters
     */
    public function testSqrtDataWithRoots(GmpMathInterface $adapter)
    {
        $theory = $adapter->getNumberTheory();

        foreach ($this->sqrt_data->has_root as $r) {
            $a = gmp_init($r->a, 10);
            $p = gmp_init($r->p, 10);
            $root1 = $theory->squareRootModP($a, $p);
            $root2 = $adapter->sub($p, $root1);
            $this->assertContains((int) gmp_strval($root1, 10), $r->res);
            $this->assertContains((int) gmp_strval($root2, 10), $r->res);
        }
    }

    /**
     * @dataProvider getAdapters
     */
    public function testCompressionConsistency(GmpMathInterface $adapter)
    {
        $theory = $adapter->getNumberTheory();
        $this->_doCompressionConsistence($adapter, $theory);
    }

    public function _doCompressionConsistence(GmpMathInterface $adapter, NumberTheory $theory)
    {
        foreach ($this->compression_data as $o) {
            // Try and regenerate the y coordinate from the parity byte
            // '04' . $x_coordinate . determined y coordinate should equal $o->decompressed
            // Tests squareRootModP which touches most functions in NumberTheory
            $y_byte = substr($o->compressed, 0, 2);
            $x_coordinate = substr($o->compressed, 2);

            $x = gmp_init($x_coordinate, 16);

            // x^3
            $x3 = $adapter->powmod($x, gmp_init(3, 10), $this->generator->getCurve()->getPrime());

            // y^2
            $y2 = $adapter->add(
                $x3,
                $this->generator->getCurve()->getB()
            );

            // y0 = sqrt(y^2)
            $y0 = $theory->squareRootModP(
                $y2,
                $this->generator->getCurve()->getPrime()
            );

            if ($y_byte == '02') {
                $y_coordinate = ($adapter->equals($adapter->mod($y0, gmp_init(2, 10)), gmp_init('0')))
                    ? gmp_strval($y0, 16)
                    : gmp_strval(gmp_sub($this->generator->getCurve()->getPrime(), $y0), 16);
            } else {
                $y_coordinate = ($adapter->equals($adapter->mod($y0, gmp_init(2, 10)), gmp_init('0')))
                    ? gmp_strval(gmp_sub($this->generator->getCurve()->getPrime(), $y0), 16)
                    : gmp_strval($y0, 16);
            }
            $y_coordinate = str_pad($y_coordinate, 64, '0', STR_PAD_LEFT);

            // Successfully regenerated uncompressed ECDSA key from the x coordinate and the parity byte.
            $this->assertEquals('04'.$x_coordinate.$y_coordinate, $o->decompressed);
        }
    }

    /**
     * @dataProvider getAdapters
     */
    public function testModFunction(GmpMathInterface $math)
    {
        // $o->compressed, $o->decompressed public key.
        // Check that we can compress a key properly (tests $math->mod())
        foreach ($this->compression_data as $o) {
            $prefix = substr($o->decompressed, 0, 2); // will be 04.

            $this->assertEquals('04', $prefix);

            // hex encoded (X,Y) coordinate of ECDSA public key.
            $x = substr($o->decompressed, 2, 64);
            $y = substr($o->decompressed, 66, 64);

            // y % 2 == 0       - true: y is even(02) / false: y is odd(03)
            $mod = $math->mod(gmp_init($y, 16), gmp_init(2, 10));
            $compressed = '0'.(($math->equals($mod, gmp_init(0))) ? '2' : '3').$x;

            // Check that the mod function reported the parity for the y value.
            $this->assertSame($compressed, $o->compressed);
        }
    }
}
