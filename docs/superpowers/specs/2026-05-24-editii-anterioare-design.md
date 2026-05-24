# Specificatie de Design: Ediții Anterioare nZEB Expo

Acest document descrie specificațiile tehnice și vizuale pentru importarea și rafinarea secțiunii „Ediții Anterioare” din Figma în WordPress, folosind constructorul de pagini Elementor V3 și suita de instrumente Stonewright MCP.

## 1. Detalii Referință Figma
*   **Figma URL:** `https://www.figma.com/design/zfoLm0i7YDmVCowIsHlBDH/nz1?node-id=97-7874&m=dev`
*   **File Key:** `zfoLm0i7YDmVCowIsHlBDH`
*   **Node ID:** `97:7874`
*   **Nume Secțiune în Figma:** `editii-anterioare`

## 2. Paleta de Culori și Token-uri Vizuale
*   **Background Secțiune:** `#130d39`
*   **Background Glowing/Orbs:** O imagine PNG de înaltă rezoluție exportată direct din Figma și setată ca imagine de fundal a secțiunii principale (`background-position: center top; background-repeat: no-repeat; background-size: cover;`).
*   **Accent Galben:** `#fdee17` (folosit pentru badge-ul anilor și butoanele/link-urile "Vezi detalii")
*   **Text Principal:** `#ffffff` (Alb)
*   **Text Secundar / Muted:** `#ebedf2` (Gri deschis)
*   **Borders:** `rgba(255, 255, 255, 0.1)` și `rgba(188, 103, 255, 0.1)`
*   **Tipografie:** Fontul Google `Montserrat`
    *   Titlu principal: `Montserrat Bold`, `64px` (Desktop), `48px` (Tabletă), `32px` (Mobil)
    *   Subtitlu: `Montserrat Regular`, `20px` (`line-height: 28px`)
    *   Titluri carduri: `Montserrat Bold`, `32px`
    *   Date/Informații card: `Montserrat Regular` (valori: `13px` White, etichete: `10px` SemiBold, uppercase, letter-spacing: `1px`)
    *   Descrieri: `Montserrat Regular`, `14px` (`line-height: 22px`)
    *   Valori statistici: `Montserrat Bold`, `20px`

## 3. Structura Containerelor (Elementor V3 Flexbox)
Ierarhia Elementor va fi creată ca containere flexibile imbricate:
1.  **Secțiune Principală (Container părinte):**
    *   Lățime: `100vw` pe toată lățimea ecranului.
    *   Fundal: Culoare `#130d39` + Imaginea PNG cu orbs de glowing centrată.
    *   Direcție flex: `column`.
    *   Padding vertical: `120px` (Desktop), `80px` (Tabletă), `60px` (Mobil).
2.  **Container Header:**
    *   Aliniere: Centrat pe orizontală.
    *   Widget 1: Heading H2 ("Momente din edițiile anterioare")
    *   Widget 2: Text Editor ("Atmosfera, expozanții și publicul...")
3.  **Container Grid Carduri (2 Coloane Desktop/Tabletă, 1 Coloană Mobil):**
    *   Lățime maximă: `1120px` (Desktop).
    *   Layout: Grid sau Flex-row cu wrap, `gap: 24px`.
    *   **Card-uri (4 bucăți: 2025, 2024, 2023, 2022):**
        *   Tip container: `Link` (învelește tot cardul pentru interactivitate).
        *   Stil: `background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.1);`.
        *   **Zona Superioară (Cover Image):**
            *   Imagine reală exportată din Figma (sideloaded direct în WP Media Library).
            *   Înălțime fixă: `304px`.
            *   Overlay: Gradient `to top` de la negru opac jos la transparent sus.
            *   Badge An: În stânga sus, poziționat absolut (`background: #fdee17; color: black; font-weight: bold; padding: 8px 16px;`).
            *   Titlu + Oraș: Poziționat absolut în stânga jos peste imagine.
        *   **Zona Inferioară (Conținut):**
            *   Padding: `32px` (Desktop), `24px` (Tabletă), `20px` (Mobil).
            *   **Row 1 (Info):** Flex row cu 3 grupuri (Dată, Locație, Vizitatori), fiecare având iconiță SVG vectorială la stânga și etichetă + valoare la dreapta.
            *   **Row 2 (Descriere):** Text descriptiv cu înălțime de rând confortabilă și culoare albastru-gri deschis.
            *   **Row 3 (Stats & CTA):** Separator border-top violet (`rgba(188, 103, 255, 0.1)`). Flex-row cu:
                *   Grup Statistici (Expozanți + Speakeri side-by-side).
                *   Link CTA: Text "Vezi detalii" (`#fdee17`) + Iconiță săgeată dreapta SVG.

## 4. Strategia de Ingestie și Sideloading
*   Niciun activ nu va fi placeholder.
*   Toate cele 4 imagini de cover pentru carduri vor fi preluate prin Figma API `/images` endpoint la rezoluție `@2x` în format PNG.
*   Toate iconițele vor fi descărcate în format SVG nativ pentru claritate maximă pe ecrane Retina.
*   Background-ul glowing va fi extras dintr-un export unitar al straturilor decorative de background din Figma.
*   Toate activele vor fi stocate în WordPress Media Library prin sistemul de sideloading automat Stonewright.

## 5. Planul de Verificare QA (Pixel-Perfect & Responsive)
*   **Pasul 1:** Rularea pipeline-ului Stonewright cu `dry_run: true` pentru validarea specificației.
*   **Pasul 2:** Aplicarea designului pe pagina dedicată (`editii-anterioare`) folosind render-ul Elementor V3.
*   **Pasul 3:** Generarea unui screenshot complet al paginii prin `stonewright/qa-screenshot-page`.
*   **Pasul 4:** Rularea unui test de comparație vizuală (`stonewright/qa-verify-against-reference`) împotriva imaginii de referință din Figma pentru a calcula scorul de similaritate (ținta: >95%).
*   **Pasul 5:** Testare responsive manuală și optimizare pe mobil/tabletă direct din controalele adaptive Elementor.
