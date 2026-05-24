# Plan de Implementare – Secțiunea „Ediții Anterioare” (Elementor V3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementarea pixel-perfect și responsive a secțiunii „Ediții Anterioare” din Figma direct în WordPress, folosind constructorul de pagini Elementor V3 (Flexbox Containers), extragerea directă a imaginilor din Figma și verificarea automată prin QA visual diff.

**Architecture:** 
1. Creăm o pagină WordPress goală (`editii-anterioare`).
2. Rulăm instrumentul orchestrator `stonewright/design-build-from-figma-reference` care preia datele din nodul Figma, extrage automat imaginile reale (fără placeholdere) și le urcă în WordPress, apoi generează structura de containere Elementor V3.
3. Extragem fundalul glowing ca o imagine PNG optimizată din Figma și o setăm ca fundal pentru secțiunea Elementor.
4. Rafinăm padding-ul, alinierea și flexibilitatea responsive a elementelor utilizând instrumentele de editare Elementor V3 din Stonewright.
5. Verificăm acuratețea vizuală prin diff automatizat cu ecranul din Figma.

---

## Proposed Changes

No source files in the local workspace repository need to be created or modified, as the entire page is built inside the WordPress database via Stonewright MCP tools. We will, however, save the plan file in the codebase for auditing.

---

## Plan Tasks

### Task 1: Crearea Paginii WordPress Țintă
Vom crea o pagină WordPress dedicată pe care să aplicăm specificațiile noastre de design.

**Files:**
- *Database entry only* (creare pagină prin REST API)

- [ ] **Pasul 1.1: Apelarea instrumentului de creare pagină WordPress**
  Apelați `stonewright/content-create-page` cu următoarele argumente:
  ```json
  {
    "title": "Ediții Anterioare",
    "status": "publish",
    "slug": "editii-anterioare"
  }
  ```
- [ ] **Pasul 1.2: Validarea succesului creării paginii**
  Verificați că răspunsul returnează un `id` pozitiv (de exemplu, `15` sau similar) și notați acest `post_id`.

---

### Task 2: Rularea Ingestiei de Design (Dry Run)
Înainte de a scrie în baza de date Elementor, vom rula o ingestie de test (dry_run: true) pentru a valida specificația extrasă din Figma și a asigura descărcarea tuturor imaginilor reale.

**Files:**
- *Figma Ingest / Sideload assets*

- [ ] **Pasul 2.1: Apelarea pipeline-ului în mod Dry-Run**
  Apelați `stonewright/design-build-from-figma-reference` cu argumentele:
  ```json
  {
    "figma_file_key": "zfoLm0i7YDmVCowIsHlBDH",
    "figma_node_id": "97-7874",
    "post_id": "<post_id_generat_la_Task_1>",
    "renderer": "elementor_v3",
    "dry_run": true,
    "skip_qa": true
  }
  ```
- [ ] **Pasul 2.2: Verificarea raportului de ingestie**
  Asigurați-vă că `success` este `true` și analizați secțiunea `warnings` din răspuns. Nu trebuie să avem erori de tipul "No mappable sections" sau blocaje de calitate.

---

### Task 3: Importul Nativ și Randarea Secțiunii (Real Run)
Aplicăm specificația de design pe pagina reală WordPress. Acest pas va genera automat structura de containere Elementor V3, va descărca și va înlocui placeholderele cu imaginile reale din Figma.

**Files:**
- *Database Elementor update*

- [ ] **Pasul 3.1: Apelarea pipeline-ului în mod Real**
  Apelați `stonewright/design-build-from-figma-reference` cu argumentele:
  ```json
  {
    "figma_file_key": "zfoLm0i7YDmVCowIsHlBDH",
    "figma_node_id": "97-7874",
    "post_id": "<post_id_generat_la_Task_1>",
    "renderer": "elementor_v3",
    "dry_run": false,
    "skip_qa": false
  }
  ```
- [ ] **Pasul 3.2: Confirmarea aplicării cu succes**
  Verificați că răspunsul arată `"success": true` și returnează un `snapshot_id` și `steps` în care pasul `apply` are statusul `ok`.

---

### Task 4: Extragerea și Aplicarea Background-ului Glowing
Fundalul glowing cu orbs colorați (nodul `97:7875`) trebuie descărcat ca o singură imagine și setat ca fundal pentru secțiunea Elementor.

**Files:**
- *Media Library Upload & Elementor Background Styling*

- [ ] **Pasul 4.1: Descărcarea imaginii de fundal din Figma**
  Apelați instrumentul `figma_get_component_image` din MCP-ul `figma-console` pentru a obține URL-ul imaginii fundalului `97:7875` (sau `97:7876` și `97:7877` îmbinate):
  ```json
  {
    "fileKey": "zfoLm0i7YDmVCowIsHlBDH",
    "nodeId": "97:7875",
    "scale": 2,
    "format": "png"
  }
  ```
- [ ] **Pasul 4.2: Sideloading-ul imaginii în WordPress**
  Utilizați URL-ul imaginii obținut la pasul anterior pentru a o încărca în WordPress apelând `stonewright/media-upload`:
  ```json
  {
    "url": "<url_imagine_figma>",
    "title": "nzeb-expo-glowing-bg",
    "alt_text": "nZEB Expo Glowing Background Orbs"
  }
  ```
  Notați URL-ul local returnat în WordPress (de exemplu, `http://mcp-test.local/wp-content/uploads/...`).
- [ ] **Pasul 4.3: Setarea imaginii ca fundal al Containerului Elementor Părinte**
  Utilizați `stonewright/elementor-v3-update-element` pe containerul principal al paginii (nodul `97:7875` sau primul container din structură) pentru a-i seta culoarea `#130d39` și imaginea de fundal cu:
  ```json
  {
    "post_id": "<post_id_generat_la_Task_1>",
    "element_id": "<id_container_parinte>",
    "settings": {
      "background_background": "classic",
      "background_image": {
        "url": "<url_local_imagine_wp>"
      },
      "background_position": "center top",
      "background_repeat": "no-repeat",
      "background_size": "cover"
    }
  }
  ```

---

### Task 5: Rafinarea Responsive și Optimizări Pixel-Perfect
Vom inspecta structura Elementor generată și vom optimiza padding-ul și comportamentul flexibil pe Mobil și Tabletă.

**Files:**
- *Elementor Element Responsive Styling*

- [ ] **Pasul 5.1: Ajustarea Padding-ului responsive pe carduri**
  Apelați `stonewright/elementor-v3-update-element` pentru fiecare dintre cele 4 carduri (`97:7885`, `97:7949`, `97:8013`, `97:8077` sau ID-urile corespunzătoare Elementor generat) pentru a asigura padding responsive curat:
  *   Desktop: `32px`
  *   Tabletă: `24px`
  *   Mobil: `20px`
- [ ] **Pasul 5.2: Verificarea și ajustarea Montserrat Google Font**
  Asigurați-vă că stilurile globale sau inline pentru titluri folosesc familia de fonturi `'Montserrat', sans-serif` și grosimile corespunzătoare (`700` pentru titluri, `600` pentru etichete, `400` pentru texte).

---

### Task 6: Verificare QA și Analiză Pixel-Diff
Vom rula suita de instrumente de control al calității (QA) din Stonewright pentru a asigura fidelitatea maximă vizuală.

**Files:**
- *QA Visual Reports*

- [ ] **Pasul 6.1: Capturarea unui Screenshot live al paginii**
  Apelați `stonewright/qa-screenshot-page` cu:
  ```json
  {
    "post_id": "<post_id_generat_la_Task_1>",
    "full_page": true
  }
  ```
  Notați URL-ul screenshot-ului rezultat.
- [ ] **Pasul 6.2: Rularea diff-ului vizual comparativ cu Figma**
  Apelați `stonewright/qa-verify-against-reference` pentru a compara pagina cu referința din Figma:
  ```json
  {
    "post_id": "<post_id_generat_la_Task_1>",
    "reference_label": "figma-zfoLm0i7YDmVCowIsHlBDH-97-7874"
  }
  ```
  Verificați că scorul de potrivire este de peste 95%. Dacă există zone problematice, reluați Task-ul 5 pentru a le ajusta fin.

---

## Verification Plan

### Automated Tests
*   **Pipeline Dry Run:** Rularea `dry_run: true` pentru a confirma validitatea schemei JSON.
*   **Visual Diff Verification:** Rularea `stonewright/qa-verify-against-reference` pentru a garanta scorul de fidelitate vizuală.

### Manual Verification
*   Deschiderea link-ului live `http://mcp-test.local/editii-anterioare/` în browserul local și redimensionarea ferestrei pentru a verifica fluiditatea fluidă a coloanelor pe mobil și tabletă.
