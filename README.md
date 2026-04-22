# Licence OCR — Docker Setup

I tried to extract data from a driver licence image with Tesseract OCR, and PHP. I wouldn't recommend it for production as the results are a bit hit and miss, but it was an interesting learning experience. LLMs apparently do a better job, but you can't really give them people's driver licences as they contain sensitive data which would probably be breaking all kinds of privacy laws.

## Stack
- PHP 8.3 + Apache
- Tesseract OCR (eng + fin language packs)
- ImageMagick (image preprocessing - currently removed functionality because of some issue)
- thiagoalessio/tesseract_ocr wrapper

## Build & run

```bash
docker compose up --build
```

## Test with curl

```bash
curl -X POST http://localhost:8080/ \
  -F "licence=@/test-images/licence_front.jpg"
```

## Example response

```json
{
    "surname": "Smith",
    "given_names": "John",
    "dob": "1969-06-09",
    "place_of_birth": "FIN",
    "issue_date": "2025-12-08",
    "expiry_date": "2040-12-08",
    "issuing_auth": "Liikenne- ja viestintävirasto",
    "driving_number": "2025 5009 0009 111 2",
    "ssn": "090669-123A",
    "categories": [
        "A",
        "BE"
    ],
    "confidence": "high"
}
```

## Add more Tesseract language packs

Edit the Dockerfile and add to the apt-get install block:

```dockerfile
tesseract-ocr-swe   # Swedish
tesseract-ocr-deu   # German
tesseract-ocr-est   # Estonian
```

Then rebuild:

```bash
docker compose up --build
```

## Notes

- `/tmp/ocr/` inside the container is used for ImageMagick preprocessed images — auto-cleaned after each request
- Upload limit: 20MB (set in php.ini via Dockerfile)
- Regex patterns in `LicenceOCR::parse()` target EU standard field numbering (1. surname, 2. given names, 3. DOB, etc.) — adjust per country layout
