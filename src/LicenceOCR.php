<?php

namespace App;

use thiagoalessio\TesseractOCR\TesseractOCR;

class LicenceOCR
{
    private string $tmpDir = '/tmp/ocr/';

    public function __construct()
    {
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0700, true);
        }
    }

    public function extract(string $uploadedFilePath): array
    {
        $this->validateFile($uploadedFilePath);

        $tmp = $this->tmpDir . uniqid('ocr_', true) . '.jpg';
        copy($uploadedFilePath, $tmp);

        $rawText = $this->runTesseract($tmp);

        @unlink($tmp);

        return $this->parse($rawText);
    }

    private function validateFile(string $path): void
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new \RuntimeException('Invalid file path');
        }

        $mime = mime_content_type($realPath);
        $allowed = ['image/jpeg', 'image/png', 'image/tiff', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            throw new \RuntimeException('Invalid file type: ' . $mime);
        }

        if (filesize($realPath) > 10 * 1024 * 1024) {
            throw new \RuntimeException('File too large');
        }
    }

    private function runTesseract(string $imagePath): string
    {
        try {
            return (new TesseractOCR($imagePath))
                ->lang('eng', 'fin')
                ->psm(3)
                ->oem(3)
                ->dpi(300)
                ->withoutTempFiles()
                ->run(timeout: 30);
        } catch (\Exception $e) {
            throw new \RuntimeException('Tesseract failed: ' . $e->getMessage());
        }
    }

    private function parse(string $text): array
    {
        // Normalise whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Fix common Tesseract misreads: "ab." -> "4b.", "ac." -> "4c.", "ad." -> "4d."
        $text = preg_replace('/\bab\./i', '4b.', $text);
        $text = preg_replace('/\bac\./i', '4c.', $text);
        $text = preg_replace('/\bad\./i', '4d.', $text);

        $dob      = $this->extractDate($text, '/3\.\s*(\d{2}[.\s]+\d{2}[.\s]+\d{4})/');
        $dobPlace = $this->extractDobPlace($text);

        return [
            'raw'            => $text,
            // 1. Surname
            'surname'        => $this->extractSurname($text),
            // 2. Given names
            'given_names'    => $this->extractGivenNames($text),
            // 3. Date and place of birth
            'dob'            => $dob,
            'place_of_birth' => $dobPlace,
            // 4a. Issue date
            'issue_date'     => $this->extractDate($text, '/4a\.\s*(\d{2}[.\s]+\d{2}[.\s]+\d{4})/'),
            // 4b. Expiry date
            'expiry_date'    => $this->extractDate($text, '/4b\.\s*(\d{2}[.\s]+\d{2}[.\s]+\d{4})/'),
            // 4c. Issuing authority
            'issuing_auth'   => $this->extractIssuingAuth($text),
            // 4d. Driving number
            'driving_number' => $this->extractField($text, '/4d\.\s*([A-Z0-9\s]{4,30}?)\s+5\./i'),
            // 5. SSN — ⚠ handle with care, GDPR sensitive
            'ssn'            => $this->extractField($text, '/5\.\s*([A-Z0-9\*\-]{4,20})/'),
            // 9. Vehicle categories
            'categories'     => $this->extractCategories($text),
            'confidence'     => $this->estimateConfidence($text),
        ];
    }

    // Surname (1.) — capitalised word(s) before "2," or "2."
    // Surname (1.) — word(s) immediately before "2," or "2."
    // Tesseract often drops the "1." label so we grab whatever sits before "2,"/"2."
    private function extractSurname(string $text): ?string
    {
        // With "1." label present
        if (preg_match('/1\.\s*([\p{L}\s\-]+?)\s+2[,.]/u', $text, $m)) {
            return trim($m[1]);
        }
        // Without "1." label — grab the token(s) immediately before "2," or "2."
        if (preg_match('/([\p{L}]+(?:\s+[\p{L}]+)*)\s+2[,.]/u', $text, $m)) {
            return trim($m[1]);
        }
        
        return null;
    }

    // Given names (2.) — between "2," or "2." and "3."
    private function extractGivenNames(string $text): ?string
    {
        if (preg_match('/2[,.]\s*([A-Za-zÄÖÅäöå\s\-]+?)\s+3\./', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    // Place of birth — country code or place name after the date in field 3
    // e.g. "3. 12.04.1985, UK" or "3. 12.04.1985 FIN"
    private function extractDobPlace(string $text): ?string
    {
        if (preg_match('/3\.\s*[\d.\s]+[,\s]+([A-ZÄÖÅa-zäöå\-]{2,})\s/', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    // Issuing authority (4c.) — between "4c." and "4d."
    // Also corrects known OCR garbling of "Liikenne- ja viestintävirasto"
    private function extractIssuingAuth(string $text): ?string
    {
        if (preg_match('/4c\.\s*(.+?)\s+4d\./i', $text, $m)) {
            $val = preg_replace('/[€|]/', '', $m[1]);
            $val = trim(preg_replace('/\s+/', ' ', $val));

            // Fuzzy-correct the one Finnish issuing authority
            if (stripos($val, 'liikenne') !== false && stripos($val, 'virasto') !== false) {
                return 'Liikenne- ja viestintävirasto';
            }

            return $val;
        }
        return null;
    }

    private function extractField(string $text, string $pattern): ?string
    {
        if (preg_match($pattern, $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    // Handles dates with spaces e.g. "08. 12.2025" or "08.12.2025"
    private function extractDate(string $text, string $pattern): ?string
    {
        $raw = $this->extractField($text, $pattern);
        if (!$raw) return null;

        $normalised = preg_replace('/\.\s+/', '.', $raw);
        $parts = preg_split('/[.\-\/]/', $normalised);

        if (count($parts) === 3) {
            [$d, $m, $y] = $parts;
            if (strlen($y) !== 4) return $raw;
            $ts = mktime(0, 0, 0, (int)$m, (int)$d, (int)$y);
            return $ts ? date('Y-m-d', $ts) : $raw;
        }
        return $raw;
    }

    // Vehicle categories (9.) — EU licence category codes
    private function extractCategories(string $text): array
    {
        // Stops at "AJOKORTTI" (FI), "JUHILUBA" (EE), "KÖRKORT" (SE) or end of string to avoid picking up unrelated text. Add more keywords if needed.
        preg_match_all('/9\.?\s*([A-Z1-2]+(?:\s[A-Z1-2]+)?)(?=\s*(AJOKORTTI|JUHILUBA|KÖRKORT)\b|$)/', $text, $matches);
        
        // Pass the group 1 match string to another regex to extract individual category codes i case they are joined as one long string.
        preg_match_all(
            '/AM|A2|A1|A|B1|BE|B|C1E|C1|CE|C|D1E|D1|DE|D|T|L/',
            $matches[1][0] ?? '',
            $matches
        );

        return array_unique($matches[0] ?? []);
    }


    private function estimateConfidence(string $text): string
    {
        $fields = [
            (bool) $this->extractSurname($text),
            (bool) $this->extractGivenNames($text),
            (bool) preg_match('/3\./', $text),
            (bool) preg_match('/4b\./', $text),
            (bool) preg_match('/5\./', $text),
        ];
        $found = array_sum($fields);
        if ($found >= 4) return 'high';
        if ($found >= 2) return 'medium';
        return 'low';
    }
}