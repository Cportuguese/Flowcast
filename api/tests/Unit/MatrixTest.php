<?php

namespace Tests\Unit;

use App\Services\Ml\Matrix;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MatrixTest extends TestCase
{
    /**
     * Assert two matrices agree to within floating-point tolerance.
     *
     * @param  array<int, array<int, float>>  $expected
     * @param  array<int, array<int, float>>  $actual
     */
    private function assertMatrixEqualsWithDelta(array $expected, array $actual, float $delta = 1e-9): void
    {
        $this->assertCount(count($expected), $actual);

        foreach ($expected as $i => $row) {
            $this->assertCount(count($row), $actual[$i]);

            foreach ($row as $j => $value) {
                $this->assertEqualsWithDelta($value, $actual[$i][$j], $delta, "cell [{$i}][{$j}]");
            }
        }
    }

    public function test_it_inverts_a_matrix_with_a_known_inverse(): void
    {
        $inverse = Matrix::inverse([
            [4.0, 7.0],
            [2.0, 6.0],
        ]);

        $this->assertMatrixEqualsWithDelta([
            [0.6, -0.7],
            [-0.2, 0.4],
        ], $inverse);
    }

    public function test_a_matrix_times_its_inverse_is_the_identity(): void
    {
        $matrix = [
            [2.0, -1.0, 0.0],
            [-1.0, 2.0, -1.0],
            [0.0, -1.0, 2.0],
        ];

        $this->assertMatrixEqualsWithDelta(
            Matrix::identity(3),
            Matrix::multiply($matrix, Matrix::inverse($matrix)),
        );
    }

    public function test_it_uses_partial_pivoting_to_invert_despite_a_zero_pivot(): void
    {
        /** A zero in the top-left would break naive elimination. */
        $matrix = [
            [0.0, 1.0],
            [1.0, 0.0],
        ];

        $this->assertMatrixEqualsWithDelta([
            [0.0, 1.0],
            [1.0, 0.0],
        ], Matrix::inverse($matrix));
    }

    public function test_it_throws_on_a_singular_matrix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('singular');

        /** The second row is twice the first, so there is no inverse. */
        Matrix::inverse([
            [1.0, 2.0],
            [2.0, 4.0],
        ]);
    }

    public function test_it_throws_on_a_non_square_matrix(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-square');

        Matrix::inverse([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]]);
    }

    public function test_it_transposes(): void
    {
        $this->assertSame([
            [1.0, 4.0],
            [2.0, 5.0],
            [3.0, 6.0],
        ], Matrix::transpose([
            [1.0, 2.0, 3.0],
            [4.0, 5.0, 6.0],
        ]));
    }

    public function test_it_multiplies_conformable_matrices(): void
    {
        $this->assertMatrixEqualsWithDelta(
            [[58.0, 64.0], [139.0, 154.0]],
            Matrix::multiply(
                [[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]],
                [[7.0, 8.0], [9.0, 10.0], [11.0, 12.0]],
            ),
        );
    }

    public function test_it_rejects_non_conformable_multiplication(): void
    {
        $this->expectException(RuntimeException::class);

        Matrix::multiply([[1.0, 2.0]], [[1.0, 2.0]]);
    }

    public function test_it_multiplies_a_matrix_by_a_vector(): void
    {
        $this->assertSame(
            [14.0, 32.0],
            Matrix::matVec([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]], [1.0, 2.0, 3.0]),
        );
    }
}
