# Fix build — encoding + speakers grid + gallery images
# Sends raw JSON bytes to avoid PowerShell UTF-8 corruption of diacritics

$ErrorActionPreference = 'Stop'
$script:wfail = 0
$base = 'http://mcp-test.local'
$cred = [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes("admin:z92d xBLZ HhZy BX4Z qwH3 uWv3"))
$authHeader = "Basic $cred"

function Post-Ability {
    param([string]$Slug, [string]$RawJsonInput, [int]$Timeout = 60, [switch]$Continue)
    $url = "$base/wp-json/wp-abilities/v1/abilities/$Slug/run"
    $wrapped = "{`"input`":$RawJsonInput}"
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($wrapped)
    $req = [System.Net.WebRequest]::Create($url)
    $req.Method = 'POST'
    $req.Headers.Add('Authorization', $authHeader)
    $req.ContentType = 'application/json; charset=utf-8'
    $req.ContentLength = $bytes.Length
    $req.Timeout = $Timeout * 1000
    try {
        $stream = $req.GetRequestStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
        $resp = $req.GetResponse()
        $sr = New-Object System.IO.StreamReader($resp.GetResponseStream())
        $json = $sr.ReadToEnd()
        $sr.Close(); $resp.Close()
        return ($json | ConvertFrom-Json)
    } catch [System.Net.WebException] {
        $errBody = ''
        if ($_.Exception.Response) {
            $ers = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $errBody = $ers.ReadToEnd()
            $ers.Close()
        }
        Write-Host "FAIL $Slug : $errBody" -ForegroundColor Red
        $script:wfail++
        if ($Continue) { return $null }
        throw
    }
}

$LOGO_ID  = 230
$LOGO_URL = 'http:\/\/mcp-test.local\/wp-content\/uploads\/2026\/05\/nzeb-expo-logo.png'
$HERO_ID  = 231
$HERO_URL = 'http:\/\/mcp-test.local\/wp-content\/uploads\/2026\/05\/nzeb-expo-hero.png'
$HOME_ID  = 258   # existing homepage — rebuild via delete+recreate

# -----------------------------------------------------------------
# Delete homepage and rebuild with correct encoding
# -----------------------------------------------------------------
Write-Host "==> Deleting old homepage $HOME_ID" -ForegroundColor Cyan
Post-Ability -Slug 'stonewright/content-delete-post' -RawJsonInput "{`"post_id`":$HOME_ID,`"force`":true}" -Continue | Out-Null

Write-Host "==> Creating new homepage" -ForegroundColor Cyan
# Unicode: București=Bucure\u0219ti, ediției=edi\u021biei, edițiile=edi\u021biile
# ș=\u0219  ț=\u021b  ă=\u0103  î=\u00ee  â=\u00e2

$r = Post-Ability -Slug 'stonewright/content-create-page' -RawJsonInput '{"title":"nZEB Expo \u2014 Edi\u021bie anterioar\u0103","status":"publish","content":""}'
$HOME_ID = [int]$r.id
Write-Host "  new home_id=$HOME_ID"

# ---- HERO section ----
Write-Host "==> Building hero" -ForegroundColor Cyan
$heroSec = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"el_type`":`"container`",
  `"settings`":{
    `"background_background`":`"classic`",`"background_color`":`"#0F1A2E`",
    `"flex_direction`":`"row`",`"align_items`":`"center`",
    `"gap`":{`"unit`":`"px`",`"size`":48},
    `"padding`":{`"unit`":`"px`",`"top`":`"120`",`"right`":`"80`",`"bottom`":`"120`",`"left`":`"80`",`"isLinked`":false}
  }
}"
$heroId = $heroSec.element_id

# Left column
$leftCol = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$heroId`",`"el_type`":`"container`",
  `"settings`":{`"flex_direction`":`"column`",`"flex_grow`":`"1`",`"gap`":{`"unit`":`"px`",`"size`":24},
    `"padding`":{`"unit`":`"px`",`"top`":`"0`",`"right`":`"0`",`"bottom`":`"0`",`"left`":`"0`",`"isLinked`":false}}
}"
$leftId = $leftCol.element_id

# Hero heading — diacritics as \u escapes
Post-Ability -Slug 'stonewright/elementor-add-heading' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$leftId`",
  `"settings`":{
    `"title`":`"nZeb Expo Bucure\u0219ti 2025`",
    `"header_size`":`"h1`",`"title_color`":`"#FFFFFF`",`"align`":`"left`",
    `"typography_typography`":`"custom`",
    `"typography_font_size`":{`"unit`":`"px`",`"size`":52},
    `"typography_font_weight`":`"700`",
    `"typography_line_height`":{`"unit`":`"em`",`"size`":1.1}
  }
}" -Continue | Out-Null

# Stats
Post-Ability -Slug 'stonewright/elementor-add-icon-list' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$leftId`",
  `"settings`":{
    `"view`":`"traditional`",`"link_click`":`"inline`",
    `"icon_list`":[
      {`"text`":`"Romexpo, Bucure\u0219ti`",`"selected_icon`":{`"value`":`"fas fa-map-marker-alt`",`"library`":`"fa-solid`"}},
      {`"text`":`"11 - 14 iunie 2026`",`"selected_icon`":{`"value`":`"fas fa-calendar`",`"library`":`"fa-solid`"}},
      {`"text`":`"12.500 participan\u021bi`",`"selected_icon`":{`"value`":`"fas fa-users`",`"library`":`"fa-solid`"}},
      {`"text`":`"120 expozant\u021bi`",`"selected_icon`":{`"value`":`"fas fa-store`",`"library`":`"fa-solid`"}}
    ],
    `"text_color`":`"#FFFFFF`",`"icon_color`":`"#F3E600`",
    `"typography_typography`":`"custom`",`"typography_font_size`":{`"unit`":`"px`",`"size`":15}
  }
}" -Continue | Out-Null

# CTAs
$ctaRow = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$leftId`",`"el_type`":`"container`",
  `"settings`":{`"flex_direction`":`"row`",`"gap`":{`"unit`":`"px`",`"size`":16},
    `"padding`":{`"unit`":`"px`",`"top`":`"0`",`"right`":`"0`",`"bottom`":`"0`",`"left`":`"0`",`"isLinked`":false}}
}"
$ctaId = $ctaRow.element_id

Post-Ability -Slug 'stonewright/elementor-add-button' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$ctaId`",
  `"settings`":{`"text`":`"Devino expozant`",`"button_text_color`":`"#FFFFFF`",`"background_color`":`"transparent`",
    `"border_border`":`"solid`",`"border_width`":{`"unit`":`"px`",`"top`":`"1`",`"right`":`"1`",`"bottom`":`"1`",`"left`":`"1`"},
    `"border_color`":`"#FFFFFF`",`"border_radius`":{`"unit`":`"px`",`"top`":`"4`",`"right`":`"4`",`"bottom`":`"4`",`"left`":`"4`"},
    `"padding`":{`"unit`":`"px`",`"top`":`"14`",`"right`":`"28`",`"bottom`":`"14`",`"left`":`"28`",`"isLinked`":false},
    `"link`":{`"url`":`"#`",`"is_external`":false,`"nofollow`":false}}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-button' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$ctaId`",
  `"settings`":{`"text`":`"Ob\u021bine bilet gratuit`",`"button_text_color`":`"#0F1A2E`",`"background_color`":`"#F3E600`",
    `"border_radius`":{`"unit`":`"px`",`"top`":`"4`",`"right`":`"4`",`"bottom`":`"4`",`"left`":`"4`"},
    `"padding`":{`"unit`":`"px`",`"top`":`"14`",`"right`":`"28`",`"bottom`":`"14`",`"left`":`"28`",`"isLinked`":false},
    `"typography_typography`":`"custom`",`"typography_font_weight`":`"700`",
    `"link`":{`"url`":`"#`",`"is_external`":false,`"nofollow`":false}}
}" -Continue | Out-Null

# Right column — hero image
$rightCol = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$heroId`",`"el_type`":`"container`",
  `"settings`":{`"flex_direction`":`"column`",`"flex_grow`":`"1`",
    `"padding`":{`"unit`":`"px`",`"top`":`"0`",`"right`":`"0`",`"bottom`":`"0`",`"left`":`"0`",`"isLinked`":false}}
}"
$rightId = $rightCol.element_id

Post-Ability -Slug 'stonewright/elementor-add-image' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$rightId`",
  `"settings`":{
    `"image`":{`"id`":$HERO_ID,`"url`":`"$HERO_URL`"},
    `"image_size`":`"full`",
    `"width`":{`"unit`":`"px`",`"size`":600},
    `"border_radius`":{`"unit`":`"px`",`"top`":`"12`",`"right`":`"12`",`"bottom`":`"12`",`"left`":`"12`"}
  }
}" -Continue | Out-Null
Write-Host "  hero OK"

# ---- AFTERMOVIE section ----
Write-Host "==> Building aftermovie section" -ForegroundColor Cyan
$sec2 = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"el_type`":`"container`",
  `"settings`":{`"background_background`":`"classic`",`"background_color`":`"#0A1429`",
    `"flex_direction`":`"column`",`"align_items`":`"center`",
    `"padding`":{`"unit`":`"px`",`"top`":`"80`",`"right`":`"80`",`"bottom`":`"80`",`"left`":`"80`",`"isLinked`":false}}
}"
$sec2id = $sec2.element_id

Post-Ability -Slug 'stonewright/elementor-add-text-editor' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec2id`",
  `"settings`":{`"editor`":`"<p style=\`"color:#F3E600;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;\`">01 \u2014 Aftermovie<\/p>`"}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-heading' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec2id`",
  `"settings`":{`"title`":`"Atmosfera edi\u021biei nZEB Expo Bucure\u0219ti 2025`",
    `"header_size`":`"h2`",`"align`":`"center`",`"title_color`":`"#FFFFFF`",
    `"typography_typography`":`"custom`",`"typography_font_size`":{`"unit`":`"px`",`"size`":36},
    `"typography_font_weight`":`"700`"}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-image' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec2id`",
  `"settings`":{
    `"image`":{`"id`":0,`"url`":`"https:\/\/images.unsplash.com\/photo-1540575467063-178a50c2df87?w=1280&h=720&fit=crop`"},
    `"image_size`":`"full`",`"width`":{`"unit`":`"%`",`"size`":100},
    `"border_radius`":{`"unit`":`"px`",`"top`":`"12`",`"right`":`"12`",`"bottom`":`"12`",`"left`":`"12`"}
  }
}" -Continue | Out-Null
Write-Host "  aftermovie OK"

# ---- SPEAKERS section ----
Write-Host "==> Building speakers grid" -ForegroundColor Cyan
$sec3 = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"el_type`":`"container`",
  `"settings`":{`"background_background`":`"classic`",`"background_color`":`"#FFFFFF`",
    `"flex_direction`":`"column`",`"align_items`":`"center`",
    `"padding`":{`"unit`":`"px`",`"top`":`"80`",`"right`":`"80`",`"bottom`":`"80`",`"left`":`"80`",`"isLinked`":false}}
}"
$sec3id = $sec3.element_id

Post-Ability -Slug 'stonewright/elementor-add-text-editor' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec3id`",
  `"settings`":{`"editor`":`"<p style=\`"color:#0F1A2E;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;\`">02 \u2014 Exper\u021bi din industrie<\/p>`"}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-heading' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec3id`",
  `"settings`":{`"title`":`"Speakerii evenimentului`",`"header_size`":`"h2`",`"align`":`"center`",`"title_color`":`"#0F1A2E`",
    `"typography_typography`":`"custom`",`"typography_font_size`":{`"unit`":`"px`",`"size`":38},`"typography_font_weight`":`"700`"}
}" -Continue | Out-Null

# Grid wrapper — 4 columns using columns_gap and preset
$spkRow1 = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec3id`",`"el_type`":`"container`",
  `"settings`":{
    `"flex_direction`":`"row`",`"flex_wrap`":`"wrap`",
    `"justify_content`":`"space-evenly`",`"align_items`":`"flex-start`",
    `"gap`":{`"unit`":`"px`",`"size`":32},
    `"padding`":{`"unit`":`"px`",`"top`":`"40`",`"right`":`"0`",`"bottom`":`"0`",`"left`":`"0`",`"isLinked`":false},
    `"width`":{`"unit`":`"%`",`"size`":100}
  }
}"
$spkId = $spkRow1.element_id

$speakers = @(
    @{ n='Adrian Stoichina';  r='Co-CEO';                   u='https://i.pravatar.cc/280?img=11' },
    @{ n='Adrian Anicane';    r='Actor';                    u='https://i.pravatar.cc/280?img=12' },
    @{ n='Alexandru Moldovan';r='Lector Univ. Dr. UTCB';    u='https://i.pravatar.cc/280?img=13' },
    @{ n='Bogdan Iliescu';    r='Ing.';                     u='https://i.pravatar.cc/280?img=14' },
    @{ n='Adrian Stoichina';  r='Co-CEO';                   u='https://i.pravatar.cc/280?img=15' },
    @{ n='Adrian Stoichina';  r='Co-CEO';                   u='https://i.pravatar.cc/280?img=16' },
    @{ n='Adrian Stoichina';  r='Co-CEO';                   u='https://i.pravatar.cc/280?img=17' },
    @{ n='Adrian Stoichina';  r='Co-CEO';                   u='https://i.pravatar.cc/280?img=18' }
)

foreach ($sp in $speakers) {
    $col = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
      `"post_id`":$HOME_ID,`"parent_id`":`"$spkId`",`"el_type`":`"container`",
      `"settings`":{`"flex_direction`":`"column`",`"align_items`":`"center`",
        `"width`":{`"unit`":`"px`",`"size`":200},
        `"padding`":{`"unit`":`"px`",`"top`":`"0`",`"right`":`"0`",`"bottom`":`"0`",`"left`":`"0`",`"isLinked`":false}}
    }"
    $colId = $col.element_id
    Post-Ability -Slug 'stonewright/elementor-add-image' -RawJsonInput "{
      `"post_id`":$HOME_ID,`"parent_id`":`"$colId`",
      `"settings`":{`"image`":{`"id`":0,`"url`":`"$($sp.u)`"},`"image_size`":`"thumbnail`",
        `"width`":{`"unit`":`"px`",`"size`":160},
        `"border_radius`":{`"unit`":`"%`",`"top`":`"50`",`"right`":`"50`",`"bottom`":`"50`",`"left`":`"50`"}}
    }" -Continue | Out-Null
    Post-Ability -Slug 'stonewright/elementor-add-heading' -RawJsonInput "{
      `"post_id`":$HOME_ID,`"parent_id`":`"$colId`",
      `"settings`":{`"title`":`"$($sp.n)`",`"header_size`":`"h4`",`"align`":`"center`",`"title_color`":`"#0F1A2E`",
        `"typography_typography`":`"custom`",`"typography_font_size`":{`"unit`":`"px`",`"size`":14},`"typography_font_weight`":`"600`"}
    }" -Continue | Out-Null
    Post-Ability -Slug 'stonewright/elementor-add-text-editor' -RawJsonInput "{
      `"post_id`":$HOME_ID,`"parent_id`":`"$colId`",
      `"settings`":{`"editor`":`"<p style=\`"text-align:center;color:#6B7280;font-size:13px;\`">$($sp.r)<\/p>`"}
    }" -Continue | Out-Null
}
Write-Host "  speakers OK ($($speakers.Count) cards)"

# ---- GALLERY section — use 4-col container grid with image widgets ----
Write-Host "==> Building gallery" -ForegroundColor Cyan
$sec4 = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"el_type`":`"container`",
  `"settings`":{`"background_background`":`"classic`",`"background_color`":`"#0F1A2E`",
    `"flex_direction`":`"column`",`"align_items`":`"center`",
    `"padding`":{`"unit`":`"px`",`"top`":`"80`",`"right`":`"80`",`"bottom`":`"80`",`"left`":`"80`",`"isLinked`":false}}
}"
$sec4id = $sec4.element_id

Post-Ability -Slug 'stonewright/elementor-add-text-editor' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec4id`",
  `"settings`":{`"editor`":`"<p style=\`"color:#F3E600;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;\`">03 \u2014 nZEB \u00een imagini<\/p>`"}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-heading' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec4id`",
  `"settings`":{`"title`":`"Galerie foto`",`"header_size`":`"h2`",`"align`":`"center`",`"title_color`":`"#FFFFFF`",
    `"typography_typography`":`"custom`",`"typography_font_size`":{`"unit`":`"px`",`"size`":38},`"typography_font_weight`":`"700`"}
}" -Continue | Out-Null

$galleryUrls = @(
    'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1559223607-a43c990c692c?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1505373877841-8d25f7d46678?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1531058020387-3be344556be6?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1497366216548-37526070297c?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1497366811353-6870744d04b2?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1551818255-e6e10975bc17?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1591115765373-5207764f72e7?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1582213782179-e0d53f98f2ca?w=400&h=300&fit=crop',
    'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=400&h=300&fit=crop&sat=-50',
    'https://images.unsplash.com/photo-1559223607-a43c990c692c?w=400&h=300&fit=crop&sat=-50'
)

# Build rows of 4
for ($row = 0; $row -lt 3; $row++) {
    $galRow = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
      `"post_id`":$HOME_ID,`"parent_id`":`"$sec4id`",`"el_type`":`"container`",
      `"settings`":{`"flex_direction`":`"row`",`"gap`":{`"unit`":`"px`",`"size`":8},`"width`":{`"unit`":`"%`",`"size`":100},
        `"padding`":{`"unit`":`"px`",`"top`":`"0`",`"right`":`"0`",`"bottom`":`"8`",`"left`":`"0`",`"isLinked`":false}}
    }"
    $galRowId = $galRow.element_id
    for ($col = 0; $col -lt 4; $col++) {
        $imgUrl = $galleryUrls[$row * 4 + $col]
        $imgUrl = $imgUrl -replace '&', '\u0026'
        Post-Ability -Slug 'stonewright/elementor-add-image' -RawJsonInput "{
          `"post_id`":$HOME_ID,`"parent_id`":`"$galRowId`",
          `"settings`":{`"image`":{`"id`":0,`"url`":`"$imgUrl`"},
            `"image_size`":`"medium`",`"width`":{`"unit`":`"%`",`"size`":100},
            `"height`":{`"unit`":`"px`",`"size`":200},
            `"object-fit`":`"cover`",
            `"border_radius`":{`"unit`":`"px`",`"top`":`"6`",`"right`":`"6`",`"bottom`":`"6`",`"left`":`"6`"}}
        }" -Continue | Out-Null
    }
}
Write-Host "  gallery OK (12 images, 3x4)"

# ---- NEWSLETTER section ----
Write-Host "==> Building newsletter" -ForegroundColor Cyan
$sec5 = Post-Ability -Slug 'stonewright/elementor-v3-add-container' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"el_type`":`"container`",
  `"settings`":{`"background_background`":`"classic`",`"background_color`":`"#EEF2FF`",
    `"flex_direction`":`"column`",`"align_items`":`"center`",
    `"padding`":{`"unit`":`"px`",`"top`":`"80`",`"right`":`"80`",`"bottom`":`"80`",`"left`":`"80`",`"isLinked`":false}}
}"
$sec5id = $sec5.element_id

Post-Ability -Slug 'stonewright/elementor-add-text-editor' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec5id`",
  `"settings`":{`"editor`":`"<p style=\`"color:#4F46E5;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;\`">04 \u2014 Newsletter<\/p>`"}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-heading' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec5id`",
  `"settings`":{`"title`":`"Fii la curent cu edi\u021biile viitoare`",`"header_size`":`"h2`",`"align`":`"center`",`"title_color`":`"#0F1A2E`",
    `"typography_typography`":`"custom`",`"typography_font_size`":{`"unit`":`"px`",`"size`":38},`"typography_font_weight`":`"700`"}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-text-editor' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec5id`",
  `"settings`":{`"editor`":`"<p style=\`"color:#374151;text-align:center;font-size:17px;max-width:680px;margin:0 auto 32px;\`">Aboneaz\u0103-te la newsletter-ul nostru \u0219i prime\u0219te cele mai recente nout\u0103\u021bi despre nZEB Expo, speakerii edi\u021biilor viitoare, programul evenimentului \u0219i oportunity\u0103\u021bi pentru expozant\u021bi.<\/p>`"}
}" -Continue | Out-Null

Post-Ability -Slug 'stonewright/elementor-add-button' -RawJsonInput "{
  `"post_id`":$HOME_ID,`"parent_id`":`"$sec5id`",
  `"settings`":{`"text`":`"Aboneaz\u0103-te la newsletter`",`"align`":`"center`",
    `"background_color`":`"#0F1A2E`",`"button_text_color`":`"#FFFFFF`",
    `"border_radius`":{`"unit`":`"px`",`"top`":`"6`",`"right`":`"6`",`"bottom`":`"6`",`"left`":`"6`"},
    `"padding`":{`"unit`":`"px`",`"top`":`"16`",`"right`":`"32`",`"bottom`":`"16`",`"left`":`"32`",`"isLinked`":false},
    `"typography_typography`":`"custom`",`"typography_font_size`":{`"unit`":`"px`",`"size`":16},`"typography_font_weight`":`"600`",
    `"link`":{`"url`":`"#newsletter`",`"is_external`":false,`"nofollow`":false}}
}" -Continue | Out-Null
Write-Host "  newsletter OK"

# ---- Set front page ----
Write-Host "==> Setting front page" -ForegroundColor Cyan
Post-Ability -Slug 'stonewright/site-set-front-page' -RawJsonInput "{`"page_id`":$HOME_ID}" | Out-Null
Write-Host "  front page: $HOME_ID"

Write-Host ""
Write-Host "==> BUILD FIXED COMPLETE (failures: $script:wfail, home=$HOME_ID)" -ForegroundColor Green
