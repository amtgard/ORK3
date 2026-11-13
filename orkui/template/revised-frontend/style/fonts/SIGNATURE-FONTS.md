# Signature Hand fonts

The eight handwriting/script faces behind the composer's and print page's **Signature Hand**
picker (the typeface of a certificate's given-by line). The vocabulary is
`system/lib/ork3/data/scroll-recipes.php` (`signatureHands`); the faces are declared in
`../scrollery-signature.css`.

All eight are licensed under the **SIL Open Font License 1.1**, which permits bundling and
self-hosting. Licence checked against each family's `METADATA.pb` in the
[google/fonts](https://github.com/google/fonts) repository (`license: "OFL"`, under `ofl/`).

Each file is the **latin subset** woff2 that Google Fonts serves for the family, downloaded
unmodified from `fonts.gstatic.com`. The latin subset covers Basic Latin and Latin-1; a given-by
name with a character outside it (for example a Latin Extended-A letter such as Ş) falls back to
Averia Serif Libre, then to the browser's cursive face, for that character only.

| File | Font | Designer | Licence | Source |
| --- | --- | --- | --- | --- |
| `GreatVibes-Regular.woff2` | Great Vibes | Robert Leuschke | SIL OFL 1.1 | https://fonts.google.com/specimen/Great+Vibes · https://github.com/googlefonts/great-vibes |
| `PinyonScript-Regular.woff2` | Pinyon Script | Nicole Fally | SIL OFL 1.1 | https://fonts.google.com/specimen/Pinyon+Script · https://github.com/SorkinType/Pinyon |
| `AlexBrush-Regular.woff2` | Alex Brush | Robert Leuschke | SIL OFL 1.1 | https://fonts.google.com/specimen/Alex+Brush · https://github.com/googlefonts/alex-brush |
| `Allura-Regular.woff2` | Allura | Robert Leuschke | SIL OFL 1.1 | https://fonts.google.com/specimen/Allura · https://github.com/googlefonts/allura |
| `MrsSaintDelafield-Regular.woff2` | Mrs Saint Delafield | Sudtipos | SIL OFL 1.1 | https://fonts.google.com/specimen/Mrs+Saint+Delafield · https://github.com/googlefonts/mrssaintdelafield |
| `HerrVonMuellerhoff-Regular.woff2` | Herr Von Muellerhoff | Sudtipos | SIL OFL 1.1 | https://fonts.google.com/specimen/Herr+Von+Muellerhoff · https://github.com/googlefonts/herrvonmuellerhoff |
| `MonsieurLaDoulaise-Regular.woff2` | Monsieur La Doulaise | Sudtipos | SIL OFL 1.1 | https://fonts.google.com/specimen/Monsieur+La+Doulaise · https://github.com/googlefonts/monsieurladoulaise |
| `CedarvilleCursive-Regular.woff2` | Cedarville Cursive | Kimberly Geswein | SIL OFL 1.1 | https://fonts.google.com/specimen/Cedarville+Cursive · https://github.com/librefonts/cedarvillecursive |

The full licence text is at https://openfontlicense.org/ and in each upstream repository's
`OFL.txt`. The project-wide `NOTICE` file is maintained separately and is not edited here.
