# Third-party notices

Tento projekt používá níže uvedené komponenty třetích stran. Licenční texty jsou
dostupné na odkazovaných adresách.

## Ikony symbolů (`SYMBOL_DEFS` v `index.html`)

### Material Design Icons
- Zdroj: https://github.com/Templarian/MaterialDesign (Pictogrammers), verze 7.4.47
- Licence: Apache License 2.0 — https://github.com/Templarian/MaterialDesign/blob/master/LICENSE
- Použití: SVG path data většiny symbolů stavebních činností.

### Game Icons
- Zdroj: https://game-icons.net, balíček `@iconify-json/game-icons` 1.2.4
- Licence: CC BY 3.0 — https://creativecommons.org/licenses/by/3.0/
- Použití: ikona `i-beam` (symbol „Ocel. konstrukce“), přeškálovaná z 512 px na 24 px mřížku.
- Attribution: game-icons.net (https://game-icons.net)

Symboly `platform` (Plošina), `silo` (Stavební silo), `tiles` (Obklady a dlažba),
`irrigation` (Závlaha), `concrete` (Autodomíchávač), `scaffolding` (Lešení)
a `drywall` (Sádrokarton) jsou vlastní kresby projektu.

## Písmo (`assets/fonts/`)

### Montserrat
- Zdroj: https://github.com/JulietaUla/Montserrat; webové řezy z balíčku
  `@fontsource/montserrat` 5.0.18, TTF z `@expo-google-fonts/montserrat` 0.4.2
- Licence: SIL Open Font License 1.1 — text v `assets/fonts/Montserrat-OFL.txt`
- Použití: firemní písmo celého rozhraní. Soubory:
  - `montserrat-{latin,latin-ext}-{400,500,600,700}.woff2` — písmo rozhraní.
    Dvě sady znaků, protože `ý` je v latin, ale `č/š/ž/ř/ě/ů` až v latin-ext.
  - `Montserrat-{Regular,Bold,Italic}.ttf` — vkládá se do PDF exportů přes jsPDF
    a používá je `generate_icons.py`. Ořezáno na latinku a latin-ext
    (fontTools subset, bez GSUB/GPOS), ~37 kB na řez místo 330 kB.

## Knihovny načítané z CDN (`index.html`)

| Knihovna | Verze | Licence |
|---|---|---|
| pdf.js | 3.11.174 | Apache-2.0 |
| three.js (+ OrbitControls, GLTFLoader, OBJLoader) | r128 | MIT |
| web-ifc | 0.0.44 | MPL-2.0 |
| Fabric.js | 5.3.1 | MIT |
| jsPDF | 2.5.1 | MIT |
| flatpickr | latest | MIT |
