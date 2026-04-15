# Facebook Emoji Scraper
A PHP script to scrape Emojis in 384x384 pixel PNG format off the Facebook CDN.  

## Requirements
 - Building the image list with `pull.php` requires `php-cli`.
 - `curl` is optional, but it enables the parallel downloader and is strongly recommended for full refreshes.

## Usage
 - `php pull.php`
 - `php pull.php --concurrency=24 --timeout=20 --retries=2`
 - `php pull.php --version=15.1`

## Noto-Compatible Export
 - `php rename_noto.php`
 - `php rename_noto.php --dry-run`
 - `php rename_noto.php --dest=png-noto --mode=rename`
 - `php rename_noto.php --keep-vs`

By default this exports the current `png` assets into `png-noto` using Noto Emoji-style names such as `emoji_u1f600.png`.
It also zero-pads low codepoints like `a9.png` to `emoji_u00a9.png` and strips `fe0f` by default to match Noto's image filename convention.
If two different Facebook files collapse to the same stripped Noto name, the script keeps the non-`fe0f` filename and reports the skipped conflict.
