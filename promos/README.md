# Plugin Store promo images

Marketing images for the Pointz listing on the Craft Plugin Store, rendered in the same theme as the
plugin's marketing page at
[justinholt.com/plugins/craft-pointz](https://justinholt.com/plugins/craft-pointz).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `pointz-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp). **Promos are JPEG, never PNG**: Chrome can only write PNG, so
`build.sh` converts with `sips` at quality 90 and deletes the intermediate. `fonts.css` is generated
and gitignored.

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover: name, tagline, app icon, price | — |
| 2 | A balance you can prove | Freya's four lots in spend order, summing to 269 |
| 3 | Back where it came from | a spend and a partial refund returning to the welcome lot |
| 4 | Every point, accounted for | **real** screenshot of a customer's balance, cropped to lots and history |
| 5 | Nothing spent until it's an order | a cart with a points adjustment, and why intent beats a debit |
| 6 | Rules a merchandiser can write | **real** screenshot of the earning rules index |
| 7 | Loyalty that adds up | refund reversal in Lite, store credit, expiry sweep, backfill |

Slides 2, 3 and 4 tell the same story, so they have to agree with each other and with
`demo-data.php`: 250 + 33 + 100 + 33 − 300 + 33 + 120 = 269. Slide 5 uses the default rate of 100
points to one unit of currency and the default cap against the order total less shipping and tax.

## Screenshots

`shots/` comes from the plugin-testing harness via `~/Sites/plugin-shots` (`specs/pointz.json`,
1000px wide), against demo data rather than the suite's fixtures:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/promos/demo-data.php
cd ~/Sites/plugin-shots && node capture.mjs ~/Sites/craft-pointz/promos/shots specs/pointz.json
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-pointz/promos/demo-data.php clean
```

The seeder writes through the Ledger and then backdates `dateCreated`. The harness is shared, so
always run `clean` afterwards. It deletes the demo rows and switches the seeded rule back off.

The balances index isn't used: its *Last movement* column reads the account cache's own timestamp,
so every demo customer shows the moment the seeder ran.

The watermark is the glyph from `src/icon-mask.svg` with the tile stripped. At watermark scale a
rounded square reads as a grey box across the slide.
