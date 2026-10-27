<?php

namespace App\Services;

class SjdmCoverage
{
    public const MESSAGE = 'This incident location is outside our coverage area. Reports are accepted only within San Jose del Monte, Bulacan. For incidents elsewhere, contact the appropriate local emergency services.';

    private ?array $polygons = null;

    public function contains(float $latitude, float $longitude): bool
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return false;
        }
        if ($this->polygons === null) {
            $feature = json_decode(file_get_contents(resource_path('geo/san-jose-del-monte.geojson')), true, flags: JSON_THROW_ON_ERROR);
            $geometry = $feature['geometry'];
            $this->polygons = match ($geometry['type']) {
                'Polygon' => [$geometry['coordinates']],
                'MultiPolygon' => $geometry['coordinates'],
                default => throw new \RuntimeException('The reporting boundary is unavailable.'),
            };
        }
        foreach ($this->polygons as $polygon) {
            $outer = $this->locate($latitude, $longitude, $polygon[0]);
            if ($outer === 0) {
                continue;
            }
            if ($outer === 2) {
                return true;
            }
            foreach (array_slice($polygon, 1) as $hole) {
                $position = $this->locate($latitude, $longitude, $hole);
                if ($position === 2) {
                    return true;
                }
                if ($position === 1) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    // GeoJSON coordinates are longitude/latitude. Ring edges count as covered.
    // 0 = outside, 1 = inside, 2 = on an edge; tolerance is under a millimeter.
    private function locate(float $latitude, float $longitude, array $ring): int
    {
        $inside = false;
        $epsilon = 1e-9;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$x1, $y1] = $ring[$j];
            [$x2, $y2] = $ring[$i];
            $length = hypot($x2 - $x1, $y2 - $y1);
            if ($length === 0.0) {
                if (hypot($longitude - $x1, $latitude - $y1) <= $epsilon) {
                    return 2;
                }

                continue;
            }
            $cross = ($longitude - $x1) * ($y2 - $y1) - ($latitude - $y1) * ($x2 - $x1);
            if (abs($cross) <= $epsilon * $length
                && $longitude >= min($x1, $x2) - $epsilon && $longitude <= max($x1, $x2) + $epsilon
                && $latitude >= min($y1, $y2) - $epsilon && $latitude <= max($y1, $y2) + $epsilon) {
                return 2;
            }
            if (($y1 > $latitude) !== ($y2 > $latitude)
                && $longitude < ($x2 - $x1) * ($latitude - $y1) / ($y2 - $y1) + $x1) {
                $inside = ! $inside;
            }
        }

        return $inside ? 1 : 0;
    }
}
