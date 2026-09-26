<?php

namespace App\Services\Ml;

use RuntimeException;

/**
 * Minimal dense linear algebra, enough to solve the ridge normal equations
 * in pure PHP. Stateless by design: every method is a pure function of its
 * arguments so the training and inference paths cannot diverge.
 *
 * Matrices are row-major `array<int, array<int, float>>`; vectors are
 * `array<int, float>`.
 */
class Matrix
{
    /**
     * Pivots smaller than this are treated as zero. Feature columns are
     * standardized before they reach here, so a pivot this small means
     * genuinely collinear columns rather than a difference in units.
     */
    private const SINGULAR_TOLERANCE = 1e-12;

    /**
     * @param  array<int, array<int, float>>  $matrix
     * @return array<int, array<int, float>>
     */
    public static function transpose(array $matrix): array
    {
        if ($matrix === []) {
            return [];
        }

        $transposed = [];

        foreach ($matrix as $i => $row) {
            foreach ($row as $j => $value) {
                $transposed[$j][$i] = $value;
            }
        }

        return array_map('array_values', array_values($transposed));
    }

    /**
     * @param  array<int, array<int, float>>  $a
     * @param  array<int, array<int, float>>  $b
     * @return array<int, array<int, float>>
     */
    public static function multiply(array $a, array $b): array
    {
        $innerA = $a === [] ? 0 : count($a[0]);
        $rowsB = count($b);

        if ($innerA !== $rowsB) {
            throw new RuntimeException("Cannot multiply a {$innerA}-column matrix by a {$rowsB}-row matrix.");
        }

        $columnsB = $b === [] ? 0 : count($b[0]);
        $product = [];

        foreach ($a as $i => $rowA) {
            $row = array_fill(0, $columnsB, 0.0);

            foreach ($rowA as $k => $valueA) {
                if ($valueA === 0.0) {
                    continue;
                }

                foreach ($b[$k] as $j => $valueB) {
                    $row[$j] += $valueA * $valueB;
                }
            }

            $product[$i] = $row;
        }

        return $product;
    }

    /**
     * @param  array<int, array<int, float>>  $matrix
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    public static function matVec(array $matrix, array $vector): array
    {
        $result = [];

        foreach ($matrix as $i => $row) {
            $sum = 0.0;

            foreach ($row as $j => $value) {
                $sum += $value * ($vector[$j] ?? 0.0);
            }

            $result[$i] = $sum;
        }

        return $result;
    }

    /**
     * @return array<int, array<int, float>>
     */
    public static function identity(int $size): array
    {
        $identity = [];

        for ($i = 0; $i < $size; $i++) {
            $identity[$i] = array_fill(0, $size, 0.0);
            $identity[$i][$i] = 1.0;
        }

        return $identity;
    }

    /**
     * Gauss-Jordan elimination with partial pivoting: augment with the
     * identity, reduce the left half to the identity, and what remains on
     * the right is the inverse.
     *
     * @param  array<int, array<int, float>>  $matrix
     * @return array<int, array<int, float>>
     *
     * @throws RuntimeException when the matrix is not square or is singular.
     */
    public static function inverse(array $matrix): array
    {
        $size = count($matrix);

        if ($size === 0) {
            throw new RuntimeException('Cannot invert an empty matrix.');
        }

        foreach ($matrix as $row) {
            if (count($row) !== $size) {
                throw new RuntimeException('Cannot invert a non-square matrix.');
            }
        }

        $augmented = [];

        for ($i = 0; $i < $size; $i++) {
            $augmented[$i] = array_merge(
                array_map(static fn ($value): float => (float) $value, array_values($matrix[$i])),
                self::identity($size)[$i],
            );
        }

        for ($column = 0; $column < $size; $column++) {
            $pivotRow = $column;

            for ($row = $column + 1; $row < $size; $row++) {
                if (abs($augmented[$row][$column]) > abs($augmented[$pivotRow][$column])) {
                    $pivotRow = $row;
                }
            }

            if (abs($augmented[$pivotRow][$column]) < self::SINGULAR_TOLERANCE) {
                throw new RuntimeException('Matrix is singular and cannot be inverted.');
            }

            if ($pivotRow !== $column) {
                [$augmented[$column], $augmented[$pivotRow]] = [$augmented[$pivotRow], $augmented[$column]];
            }

            $pivot = $augmented[$column][$column];

            for ($j = 0; $j < 2 * $size; $j++) {
                $augmented[$column][$j] /= $pivot;
            }

            for ($row = 0; $row < $size; $row++) {
                if ($row === $column) {
                    continue;
                }

                $factor = $augmented[$row][$column];

                if ($factor === 0.0) {
                    continue;
                }

                for ($j = 0; $j < 2 * $size; $j++) {
                    $augmented[$row][$j] -= $factor * $augmented[$column][$j];
                }
            }
        }

        $inverse = [];

        for ($i = 0; $i < $size; $i++) {
            $inverse[$i] = array_slice($augmented[$i], $size);
        }

        return $inverse;
    }
}
