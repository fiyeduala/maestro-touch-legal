<?php

/*
 * Side-by-side visual comparison of two screenshot sets (reference WordPress site vs the Laravel build).
 *
 * Usage: php tools/site-audit/compare.php [referenceDir] [buildDir] [outDir]
 *   defaults: docs/reference-screenshots  docs/visual-comparison/build  docs/visual-comparison
 *
 * For each pair it writes <name>-side-by-side.jpg (reference left, build right, top of page) and reports:
 *  - page height of each (a rough check that no section is missing or duplicated);
 *  - how different the first screen is (mean colour difference, 0 = identical, sampled on a grid).
 * The numbers only point at pages worth looking at; the side-by-side images are what a person should judge.
 */

$ref = $argv[1] ?? 'docs/reference-screenshots';
$build = $argv[2] ?? 'docs/visual-comparison/build';
$out = $argv[3] ?? 'docs/visual-comparison';
$maxHeight = 2400; // px of the reference page shown in the side-by-side

if (! extension_loaded('gd')) {
    fwrite(STDERR, "The PHP GD extension is required.\n");
    exit(1);
}

$rows = [];
foreach (glob("{$ref}/*.png") as $refFile) {
    $name = basename($refFile, '.png');
    $buildFile = "{$build}/{$name}.png";
    if (! is_file($buildFile)) {
        $rows[] = [$name, '-', '-', '-', 'no build screenshot'];

        continue;
    }

    $a = imagecreatefrompng($refFile);
    $b = imagecreatefrompng($buildFile);
    [$aw, $ah] = [imagesx($a), imagesy($a)];
    [$bw, $bh] = [imagesx($b), imagesy($b)];

    // Scale the build to the reference width so both compare at the same size.
    if ($bw !== $aw) {
        $scaled = imagescale($b, $aw, (int) round($bh * $aw / $bw));
        imagedestroy($b);
        $b = $scaled;
        [$bw, $bh] = [imagesx($b), imagesy($b)];
    }

    // First screen: the viewport height the screenshot tool used (900 desktop, 844 mobile at 2x).
    $screen = str_ends_with($name, '-desktop') ? 900 : (int) round(844 * $aw / 390);
    $diff = meanDifference($a, $b, $aw, min($screen, $ah, $bh));

    $h = min($maxHeight * ($aw > 1000 ? 1 : 2), max($ah, $bh));
    $gap = 16;
    $canvas = imagecreatetruecolor($aw * 2 + $gap, $h);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 0, 255));
    imagecopy($canvas, $a, 0, 0, 0, 0, $aw, min($h, $ah));
    imagecopy($canvas, $b, $aw + $gap, 0, 0, 0, $bw, min($h, $bh));
    $thumb = imagescale($canvas, (int) round((($aw * 2 + $gap) * 0.5)));
    imagejpeg($thumb, "{$out}/{$name}-side-by-side.jpg", 80);
    foreach ([$a, $b, $canvas, $thumb] as $img) {
        imagedestroy($img);
    }

    $ratio = $bh / $ah;
    $flag = $diff > 25 ? 'look closely' : ($ratio < 0.6 || $ratio > 1.6 ? 'height differs a lot' : 'ok');
    $rows[] = [$name, $ah, $bh, number_format($diff, 1), $flag];
}

$md = "| Page | Reference height | Build height | First-screen difference | Flag |\n|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $md .= '| '.implode(' | ', $r)." |\n";
}
file_put_contents("{$out}/comparison.md", $md);
echo $md;

/** Mean per-channel absolute difference (0-255) over a sampling grid. */
function meanDifference($a, $b, int $width, int $height): float
{
    $sum = 0;
    $n = 0;
    for ($y = 0; $y < $height; $y += 4) {
        for ($x = 0; $x < $width; $x += 4) {
            $p = imagecolorat($a, $x, $y);
            $q = imagecolorat($b, $x, $y);
            $sum += abs((($p >> 16) & 255) - (($q >> 16) & 255)) + abs((($p >> 8) & 255) - (($q >> 8) & 255)) + abs(($p & 255) - ($q & 255));
            $n += 3;
        }
    }

    return $n ? $sum / $n : 0.0;
}
