# NG AI Media Assistant

WordPress plugin that suggests alt text and a short description for Media Library images using Google Gemini, in Arabic, English, French or German. You use your own Gemini API key.

Product direction, scope and delivery: Nada Gamal.

## How it works
- On upload (optional) or with "Generate for images without alt text" (10 per run), the image (the Large size, max 4 MB) is sent to the Gemini API with your key and a prompt asking for factual alt text under 125 characters plus a short description.
- Default mode is a review queue: suggestions are stored and applied only when you approve them. Auto mode fills alt text only where it is empty and never overwrites existing alt text.
- Output is parsed as JSON, tags stripped, lengths capped. Garbage answers and API errors are recorded and change nothing.
- API key: define `NGAMA_KEY` in wp-config.php (recommended) or save it in settings (AES-256-GCM encrypted, never shown again).
- Labels in English, Arabic (RTL), French, German (FR/DE machine translated, review needed). Alt text language is set in settings or follows the site language.

## Privacy
Images are sent to Google when you use this plugin. Do not use it on images that must not leave your server. Add a note to your privacy policy.

## Tests
`tests/run-tests.php`: 30 checks, 0 failed (WordPress + PHP 8.2, Playground) against a local mock of the Gemini endpoint: key handling, request format (key header, base64 image, language, context), review vs auto mode, no overwrite of existing alt, on-upload toggle, wrong key, garbage and code-fenced answers, HTML stripping, non-images, capability checks, 4-language page, escaping.

## Honest limits
- Tested against a mock, NOT the live Gemini API. The request shape follows the public generateContent documentation (x-goog-api-key header, inline_data base64), but a live test with a real key is still needed.
- Gemini model names change and older ones are retired. The model is a setting (default `gemini-flash-latest`); set one your key can use.
- Alt text quality depends on the model: review before approving, especially for medical or sensitive images.
- Activation checked beside real Elementor 4.4.2 (no errors). Not tested in the Elementor editor, with Bricks, or on a live host.
