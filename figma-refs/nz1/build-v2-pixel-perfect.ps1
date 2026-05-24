# nZ1 pixel-perfect rebuild — v2 (fix header logo, hero 2col, speakers grid, gallery)
# Uses stonewright/* abilities only. Logo ID=230, Hero ID=231.

$ErrorActionPreference = 'Stop'
$script:wfail = 0
$base = 'http://mcp-test.local'
$user = 'admin'
$pass = 'z92d xBLZ HhZy BX4Z qwH3 uWv3'
$cred = [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes("${user}:${pass}"))
$headers = @{ Authorization = "Basic $cred"; 'Content-Type' = 'application/json' }
$results = @{}

# Media IDs from previous upload
$LOGO_ID  = 230
$LOGO_URL = 'http://mcp-test.local/wp-content/uploads/2026/05/nzeb-expo-logo.png'
$HERO_ID  = 231
$HERO_URL = 'http://mcp-test.local/wp-content/uploads/2026/05/nzeb-expo-hero.png'

function Invoke-Ability {
    param([string]$Name, $InputData, [int]$Timeout = 60, [switch]$Continue)
    $url = "$base/wp-json/wp-abilities/v1/abilities/$Name/run"
    $body = @{ input = $InputData } | ConvertTo-Json -Depth 30 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
    try {
        $r = Invoke-WebRequest -Uri $url -Method POST -Headers $headers -Body $bytes -UseBasicParsing -TimeoutSec $Timeout
        return ($r.Content | ConvertFrom-Json)
    } catch {
        $resp = ''
        if ($_.Exception.Response) {
            try { $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream()); $resp = $sr.ReadToEnd() } catch {}
        }
        Write-Host "FAIL: $Name" -ForegroundColor Red
        if ($resp) { Write-Host "  $($resp.Substring(0, [Math]::Min(300, $resp.Length)))" -ForegroundColor Red }
        $script:wfail++
        if ($Continue) { return $null }
        throw
    }
}

function Step($label, [scriptblock]$body) {
    Write-Host ""
    Write-Host "==> $label" -ForegroundColor Cyan
    & $body
}

# -----------------------------------------------------------------------
# 0. Delete old templates + old homepage
# -----------------------------------------------------------------------
Step "0. Clean old templates" {
    $list = Invoke-Ability -Name 'stonewright/theme-builder-list-templates' -InputData @{}
    foreach ($t in $list.templates) {
        if ($t.template_type -eq 'header' -or $t.template_type -eq 'footer') {
            Write-Host "  delete $($t.template_type) id=$($t.template_id)"
            Invoke-Ability -Name 'stonewright/theme-builder-delete-template' -InputData @{ template_id = [int]$t.template_id } -Continue | Out-Null
        }
    }
}

# -----------------------------------------------------------------------
# 1. Menus — clean + recreate
# -----------------------------------------------------------------------
Step "1a. Clean prior menus" {
    try {
        $list = Invoke-Ability -Name 'stonewright/menu-list' -InputData @{}
        foreach ($m in $list.menus) {
            if ($m.name -like 'nz1-*') {
                $mid = if ($m.id) { [int]$m.id } else { [int]$m.menu_id }
                Write-Host "  delete menu: $($m.name) (id=$mid)"
                Invoke-Ability -Name 'stonewright/menu-delete' -InputData @{ menu_id = $mid } -Continue | Out-Null
            }
        }
    } catch { Write-Host "  (menu clean err: $($_.Exception.Message))" -ForegroundColor Yellow }
}

Step "1. Create menus" {
    foreach ($menu in @(
        @{ name = 'nz1-top';       items = @('Ediții', 'Despre Nzeb Expo', 'Media', 'Noutăți', 'Echipă') },
        @{ name = 'nz1-secondary'; items = @('Program', 'Speakeri', 'Expozanți', 'Parteneri') },
        @{ name = 'nz1-footer-1';  items = @('Despre nZEB Expo', 'Misiune', 'Pentru cine este', 'nZEB Expo în cifre', 'Media Kit & Presă', 'Ediții') },
        @{ name = 'nz1-footer-2';  items = @('Program', 'Speakeri', 'Parteneri', 'Informații rapide', 'Hartă eveniment', 'Bilet gratuit', 'Devino expozant') }
    )) {
        $r = Invoke-Ability -Name 'stonewright/menu-create' -InputData @{ name = $menu.name }
        $results[$menu.name] = $r.menu_id
        foreach ($title in $menu.items) {
            Invoke-Ability -Name 'stonewright/menu-add-item' -InputData @{ menu_id = [int]$r.menu_id; title = $title; url = '#' } -Continue | Out-Null
        }
        Write-Host "  $($menu.name) id=$($r.menu_id) ($(($menu.items).Count) items)"
    }
}

# -----------------------------------------------------------------------
# 2. Header — Logo + 2-row nav (dark navy, logo left, menu center, CTAs right)
# -----------------------------------------------------------------------
Step "2. Header Theme Builder template" {
    $tpl = Invoke-Ability -Name 'stonewright/theme-builder-create-template' -InputData @{
        title         = 'nZ1 Header'
        template_type = 'header'
    }
    $header_id = [int]$tpl.template_id
    $results['header_id'] = $header_id
    Write-Host "  header_id=$header_id"

    # Root container — dark navy full-width, flex col
    $root = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $header_id
        el_type = 'container'
        settings = @{
            background_background = 'classic'
            background_color      = '#0F1A2E'
            flex_direction        = 'column'
            padding = @{ unit='px'; top='0'; right='0'; bottom='0'; left='0'; isLinked=$false }
        }
    }
    $root_id = $root.element_id

    # ---- Row 1: Logo + top nav + lang ----
    $row1 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id   = $header_id
        parent_id = $root_id
        el_type   = 'container'
        settings  = @{
            flex_direction      = 'row'
            align_items         = 'center'
            justify_content     = 'space-between'
            background_background = 'classic'
            background_color    = '#0F1A2E'
            padding = @{ unit='px'; top='16'; right='80'; bottom='16'; left='80'; isLinked=$false }
        }
    }
    $row1_id = $row1.element_id

    # Logo image
    Invoke-Ability -Name 'stonewright/elementor-add-image' -InputData @{
        post_id   = $header_id
        parent_id = $row1_id
        settings  = @{
            image      = @{ id = $LOGO_ID; url = $LOGO_URL }
            image_size = 'thumbnail'
            width      = @{ unit='px'; size=56 }
            link_to    = 'home'
        }
    } -Continue | Out-Null

    # Top nav menu
    Invoke-Ability -Name 'stonewright/elementor-add-nav-menu' -InputData @{
        post_id   = $header_id
        parent_id = $row1_id
        settings  = @{
            menu                            = "$($results['nz1-top'])"
            layout                          = 'horizontal'
            align_items                     = 'center'
            pointer                         = 'none'
            menu_typography_typography      = 'custom'
            menu_typography_font_size       = @{ unit='px'; size=14 }
            menu_typography_text_transform  = 'uppercase'
            color_menu_item                 = '#FFFFFF'
            color_menu_item_hover           = '#F3E600'
        }
    } -Continue | Out-Null

    # Lang switcher text
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id   = $header_id
        parent_id = $row1_id
        settings  = @{ editor = '<p style="color:#FFFFFF;font-size:14px;margin:0;">RO &nbsp;|&nbsp; EN</p>' }
    } -Continue | Out-Null

    # ---- Row 2: Edition label + secondary nav + CTA buttons ----
    $row2 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id   = $header_id
        parent_id = $root_id
        el_type   = 'container'
        settings  = @{
            flex_direction      = 'row'
            align_items         = 'center'
            justify_content     = 'space-between'
            background_background = 'classic'
            background_color    = '#0A1424'
            padding = @{ unit='px'; top='12'; right='80'; bottom='12'; left='80'; isLinked=$false }
        }
    }
    $row2_id = $row2.element_id

    # Edition label
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id   = $header_id
        parent_id = $row2_id
        settings  = @{ editor = '<p style="color:#F3E600;font-size:14px;font-weight:600;margin:0;">Ediție București 2026</p>' }
    } -Continue | Out-Null

    # Secondary nav
    Invoke-Ability -Name 'stonewright/elementor-add-nav-menu' -InputData @{
        post_id   = $header_id
        parent_id = $row2_id
        settings  = @{
            menu                        = "$($results['nz1-secondary'])"
            layout                      = 'horizontal'
            align_items                 = 'center'
            pointer                     = 'none'
            menu_typography_typography  = 'custom'
            menu_typography_font_size   = @{ unit='px'; size=14 }
            color_menu_item             = '#FFFFFF'
            color_menu_item_hover       = '#F3E600'
        }
    } -Continue | Out-Null

    # CTA container (right side)
    $cta_row = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id   = $header_id
        parent_id = $row2_id
        el_type   = 'container'
        settings  = @{
            flex_direction  = 'row'
            align_items     = 'center'
            gap             = @{ unit='px'; size=12 }
            padding = @{ unit='px'; top='0'; right='0'; bottom='0'; left='0'; isLinked=$false }
        }
    }
    $cta_id = $cta_row.element_id

    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id   = $header_id
        parent_id = $cta_id
        settings  = @{
            text                = 'Devino expozant'
            button_text_color   = '#FFFFFF'
            background_color    = 'transparent'
            border_border       = 'solid'
            border_width        = @{ unit='px'; top='1'; right='1'; bottom='1'; left='1' }
            border_color        = '#FFFFFF'
            border_radius       = @{ unit='px'; top='4'; right='4'; bottom='4'; left='4' }
            padding             = @{ unit='px'; top='10'; right='18'; bottom='10'; left='18'; isLinked=$false }
            link                = @{ url='#'; is_external=$false; nofollow=$false }
        }
    } -Continue | Out-Null

    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id   = $header_id
        parent_id = $cta_id
        settings  = @{
            text                = 'Obține bilet gratuit'
            button_text_color   = '#0F1A2E'
            background_color    = '#F3E600'
            border_radius       = @{ unit='px'; top='4'; right='4'; bottom='4'; left='4' }
            padding             = @{ unit='px'; top='10'; right='18'; bottom='10'; left='18'; isLinked=$false }
            typography_typography   = 'custom'
            typography_font_weight  = '700'
            link                = @{ url='#'; is_external=$false; nofollow=$false }
        }
    } -Continue | Out-Null

    # Conditions
    Invoke-Ability -Name 'stonewright/theme-builder-set-conditions' -InputData @{
        template_id = $header_id
        conditions  = @( @{ type='include'; name='general' } )
    } | Out-Null
    Write-Host "  header conditions: include/general"
}

# -----------------------------------------------------------------------
# 3. Footer
# -----------------------------------------------------------------------
Step "3. Footer Theme Builder template" {
    $tpl = Invoke-Ability -Name 'stonewright/theme-builder-create-template' -InputData @{
        title = 'nZ1 Footer'; template_type = 'footer'
    }
    $footer_id = [int]$tpl.template_id
    $results['footer_id'] = $footer_id
    Write-Host "  footer_id=$footer_id"

    $root = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $footer_id; el_type = 'container'
        settings = @{
            background_background = 'classic'; background_color = '#0F1A2E'
            flex_direction = 'column'
            padding = @{ unit='px'; top='64'; right='80'; bottom='24'; left='80'; isLinked=$false }
        }
    }
    $root_id = $root.element_id

    # 3-col row
    $cols_row = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $footer_id; parent_id = $root_id; el_type = 'container'
        settings = @{ flex_direction='row'; align_items='flex-start'; gap=@{ unit='px'; size=64 };
            padding = @{ unit='px'; top='0'; right='0'; bottom='32'; left='0'; isLinked=$false } }
    }
    $cols_id = $cols_row.element_id

    # Col 1 — nZEB Expo links
    $c1 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $footer_id; parent_id = $cols_id; el_type = 'container'
        settings = @{ flex_direction='column'; flex_grow='1'; padding=@{unit='px';top='0';right='0';bottom='0';left='0';isLinked=$false} }
    }
    $c1_id = $c1.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$footer_id; parent_id=$c1_id
        settings=@{ title='nZEB Expo'; header_size='h3'; title_color='#FFFFFF'; typography_typography='custom'; typography_font_size=@{unit='px';size=20}; typography_font_weight='600' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id=$footer_id; parent_id=$c1_id
        settings=@{
            view='traditional'; link_click='inline'
            icon_list=@(
                @{ text='Despre nZEB Expo'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Misiune'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Pentru cine este'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='nZEB Expo în cifre'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Media Kit & Presă'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Ediții'; link=@{url='#';is_external=$false;nofollow=$false} }
            )
            text_color='#9CA3B7'; icon_color='transparent'
            typography_typography='custom'; typography_font_size=@{unit='px';size=14}
        }
    } -Continue | Out-Null

    # Col 2 — București 2026
    $c2 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$footer_id; parent_id=$cols_id; el_type='container'
        settings=@{ flex_direction='column'; flex_grow='1'; padding=@{unit='px';top='0';right='0';bottom='0';left='0';isLinked=$false} }
    }
    $c2_id = $c2.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$footer_id; parent_id=$c2_id
        settings=@{ title='București 2026'; header_size='h3'; title_color='#FFFFFF'; typography_typography='custom'; typography_font_size=@{unit='px';size=20}; typography_font_weight='600' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id=$footer_id; parent_id=$c2_id
        settings=@{
            view='traditional'; link_click='inline'
            icon_list=@(
                @{ text='Program'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Speakeri'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Parteneri'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Informații rapide'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Hartă eveniment'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Bilet gratuit'; link=@{url='#';is_external=$false;nofollow=$false} },
                @{ text='Devino expozant'; link=@{url='#';is_external=$false;nofollow=$false} }
            )
            text_color='#9CA3B7'; icon_color='transparent'
            typography_typography='custom'; typography_font_size=@{unit='px';size=14}
        }
    } -Continue | Out-Null

    # Col 3 — Contact + social
    $c3 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$footer_id; parent_id=$cols_id; el_type='container'
        settings=@{ flex_direction='column'; flex_grow='1'; padding=@{unit='px';top='0';right='0';bottom='0';left='0';isLinked=$false} }
    }
    $c3_id = $c3.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$footer_id; parent_id=$c3_id
        settings=@{ title='Contact'; header_size='h3'; title_color='#FFFFFF'; typography_typography='custom'; typography_font_size=@{unit='px';size=20}; typography_font_weight='600' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id=$footer_id; parent_id=$c3_id
        settings=@{
            view='traditional'; link_click='inline'
            icon_list=@(
                @{ text='contact@nzebexpo.ro'; link=@{url='mailto:contact@nzebexpo.ro'}; selected_icon=@{value='fas fa-envelope';library='fa-solid'} },
                @{ text='+40 XXX XXX XXX'; link=@{url='tel:+40000000000'}; selected_icon=@{value='fas fa-phone';library='fa-solid'} }
            )
            text_color='#FFFFFF'; icon_color='#F3E600'
        }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-social-icons' -InputData @{
        post_id=$footer_id; parent_id=$c3_id
        settings=@{
            shape='rounded'; icon_size=@{unit='px';size=18}
            social_icon_list=@(
                @{ social_icon=@{value='fab fa-facebook';library='fa-brands'}; link=@{url='#'} },
                @{ social_icon=@{value='fab fa-instagram';library='fa-brands'}; link=@{url='#'} },
                @{ social_icon=@{value='fab fa-linkedin';library='fa-brands'}; link=@{url='#'} },
                @{ social_icon=@{value='fab fa-youtube';library='fa-brands'}; link=@{url='#'} },
                @{ social_icon=@{value='fab fa-tiktok';library='fa-brands'}; link=@{url='#'} }
            )
        }
    } -Continue | Out-Null

    # Divider + copyright
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id=$footer_id; parent_id=$root_id
        settings=@{ editor='<hr style="border-color:#1F2A44;margin:0 0 20px 0;"><p style="text-align:center;color:#9CA3B7;font-size:13px;">© 2026 nZEB Expo. Toate drepturile rezervate. &nbsp;|&nbsp; <a href="#" style="color:#F3E600;">Politică de confidențialitate</a> &nbsp;|&nbsp; <a href="#" style="color:#F3E600;">Termeni și condiții</a> &nbsp;|&nbsp; <a href="#" style="color:#F3E600;">Politică cookies</a></p>' }
    } -Continue | Out-Null

    Invoke-Ability -Name 'stonewright/theme-builder-set-conditions' -InputData @{
        template_id = $footer_id
        conditions  = @( @{ type='include'; name='general' } )
    } | Out-Null
    Write-Host "  footer conditions: include/general"
}

# -----------------------------------------------------------------------
# 4. Homepage — pixel-perfect 5 sections
# -----------------------------------------------------------------------
Step "4. Homepage" {
    $page = Invoke-Ability -Name 'stonewright/content-create-page' -InputData @{
        title='nZEB Expo — Ediție anterioară'; status='publish'; content=''
    }
    $home_id = [int]$page.id
    $results['homepage_id'] = $home_id
    Write-Host "  homepage_id=$home_id"

    # ---- HERO: 2 columns dark navy ----
    $hero_sec = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $home_id; el_type = 'container'
        settings = @{
            background_background = 'classic'; background_color = '#0F1A2E'
            flex_direction = 'row'; align_items = 'center'
            gap = @{ unit='px'; size=48 }
            padding = @{ unit='px'; top='120'; right='80'; bottom='120'; left='80'; isLinked=$false }
            min_height = @{ unit='vh'; size=80 }
        }
    }
    $hero_id = $hero_sec.element_id

    # Left col — text
    $left = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; parent_id=$hero_id; el_type='container'
        settings=@{ flex_direction='column'; flex_grow='1'; gap=@{unit='px';size=24};
            padding=@{unit='px';top='0';right='0';bottom='0';left='0';isLinked=$false} }
    }
    $left_id = $left.element_id

    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$home_id; parent_id=$left_id
        settings=@{
            title='nZeb Expo <span style="color:#F3E600">București</span> 2025'
            header_size='h1'; title_color='#FFFFFF'; align='left'
            typography_typography='custom'; typography_font_size=@{unit='px';size=56}
            typography_font_weight='700'; typography_line_height=@{unit='em';size=1.1}
        }
    } -Continue | Out-Null

    # Stats icon list
    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id=$home_id; parent_id=$left_id
        settings=@{
            view='inline'; icon_align='left'; gap=@{unit='px';size=24}
            icon_list=@(
                @{ text='Romexpo, București'; selected_icon=@{value='fas fa-map-marker-alt';library='fa-solid'} },
                @{ text='11 - 14 iunie 2026'; selected_icon=@{value='fas fa-calendar';library='fa-solid'} },
                @{ text='12.500 participanți'; selected_icon=@{value='fas fa-users';library='fa-solid'} },
                @{ text='120 expozanți'; selected_icon=@{value='fas fa-store';library='fa-solid'} }
            )
            text_color='#FFFFFF'; icon_color='#F3E600'
            typography_typography='custom'; typography_font_size=@{unit='px';size=15}
        }
    } -Continue | Out-Null

    # CTA buttons
    $cta = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; parent_id=$left_id; el_type='container'
        settings=@{ flex_direction='row'; gap=@{unit='px';size=16};
            padding=@{unit='px';top='0';right='0';bottom='0';left='0';isLinked=$false} }
    }
    $cta_id = $cta.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id=$home_id; parent_id=$cta_id
        settings=@{ text='Devino expozant'; button_text_color='#FFFFFF'; background_color='transparent'
            border_border='solid'; border_width=@{unit='px';top='1';right='1';bottom='1';left='1'};
            border_color='#FFFFFF'; border_radius=@{unit='px';top='4';right='4';bottom='4';left='4'}
            padding=@{unit='px';top='14';right='28';bottom='14';left='28';isLinked=$false}
            link=@{url='/devino-expozant';is_external=$false;nofollow=$false} }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id=$home_id; parent_id=$cta_id
        settings=@{ text='Obține bilet gratuit'; button_text_color='#0F1A2E'; background_color='#F3E600'
            border_radius=@{unit='px';top='4';right='4';bottom='4';left='4'}
            padding=@{unit='px';top='14';right='28';bottom='14';left='28';isLinked=$false}
            typography_typography='custom'; typography_font_weight='700'
            link=@{url='/bilet-gratuit';is_external=$false;nofollow=$false} }
    } -Continue | Out-Null

    # Right col — hero image
    $right = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; parent_id=$hero_id; el_type='container'
        settings=@{ flex_direction='column'; flex_grow='1';
            padding=@{unit='px';top='0';right='0';bottom='0';left='0';isLinked=$false} }
    }
    $right_id = $right.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-image' -InputData @{
        post_id=$home_id; parent_id=$right_id
        settings=@{
            image=@{ id=$HERO_ID; url=$HERO_URL }
            image_size='full'; width=@{unit='%';size=100}
            border_radius=@{unit='px';top='12';right='12';bottom='12';left='12'}
        }
    } -Continue | Out-Null
    Write-Host "  hero 2-col built"

    # ---- AFTERMOVIE section ----
    $sec2 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; el_type='container'
        settings=@{ background_background='classic'; background_color='#0A1429'; flex_direction='column'; align_items='center'
            padding=@{unit='px';top='80';right='80';bottom='80';left='80';isLinked=$false} }
    }
    $sec2_id = $sec2.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id=$home_id; parent_id=$sec2_id
        settings=@{ editor='<p style="color:#F3E600;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px;">01 — Aftermovie</p>' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$home_id; parent_id=$sec2_id
        settings=@{ title='Atmosfera ediției nZEB Expo București 2025'; header_size='h2'; align='center'; title_color='#FFFFFF'
            typography_typography='custom'; typography_font_size=@{unit='px';size=38}; typography_font_weight='700' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-image' -InputData @{
        post_id=$home_id; parent_id=$sec2_id
        settings=@{
            image=@{ id=0; url='https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=1280&h=720&fit=crop' }
            image_size='large'; width=@{unit='%';size=100}
            border_radius=@{unit='px';top='12';right='12';bottom='12';left='12'}
            link_to='custom'; link=@{url='#video';is_external=$false;nofollow=$false}
        }
    } -Continue | Out-Null
    Write-Host "  aftermovie built"

    # ---- SPEAKERS section (white bg, grid) ----
    $sec3 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; el_type='container'
        settings=@{ background_background='classic'; background_color='#FFFFFF'; flex_direction='column'; align_items='center'
            padding=@{unit='px';top='80';right='80';bottom='80';left='80';isLinked=$false} }
    }
    $sec3_id = $sec3.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id=$home_id; parent_id=$sec3_id
        settings=@{ editor='<p style="color:#0F1A2E;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px;">02 — Experți din industrie</p>' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$home_id; parent_id=$sec3_id
        settings=@{ title='Speakerii evenimentului'; header_size='h2'; align='center'; title_color='#0F1A2E'
            typography_typography='custom'; typography_font_size=@{unit='px';size=38}; typography_font_weight='700' }
    } -Continue | Out-Null

    # Speakers row — 4 columns with images
    $spk_row = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; parent_id=$sec3_id; el_type='container'
        settings=@{ flex_direction='row'; flex_wrap='wrap'; gap=@{unit='px';size=32}; align_items='flex-start'; justify_content='center'
            padding=@{unit='px';top='40';right='0';bottom='0';left='0';isLinked=$false} }
    }
    $spk_id = $spk_row.element_id

    $speakers = @(
        @{ name='Adrian Stoichina'; role='Co-CEO'; avatar='https://i.pravatar.cc/280?u=Adrian1' },
        @{ name='Adrian Anicăane'; role='Actor'; avatar='https://i.pravatar.cc/280?u=Adrian2' },
        @{ name='Alexandru Moldovan'; role='Lector Univ. Dr. UTCB'; avatar='https://i.pravatar.cc/280?u=Alexandru' },
        @{ name='Bogdan Iliescu'; role='Ing.'; avatar='https://i.pravatar.cc/280?u=Bogdan' },
        @{ name='Adrian Stoichina'; role='Co-CEO'; avatar='https://i.pravatar.cc/280?u=Adrian5' },
        @{ name='Adrian Stoichina'; role='Co-CEO'; avatar='https://i.pravatar.cc/280?u=Adrian6' },
        @{ name='Adrian Stoichina'; role='Co-CEO'; avatar='https://i.pravatar.cc/280?u=Adrian7' },
        @{ name='Adrian Stoichina'; role='Co-CEO'; avatar='https://i.pravatar.cc/280?u=Adrian8' }
    )

    foreach ($sp in $speakers) {
        $col = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
            post_id=$home_id; parent_id=$spk_id; el_type='container'
            settings=@{ flex_direction='column'; align_items='center'; width=@{unit='%';size=22};
                padding=@{unit='px';top='0';right='0';bottom='0';left='0';isLinked=$false} }
        }
        $col_id = $col.element_id
        Invoke-Ability -Name 'stonewright/elementor-add-image' -InputData @{
            post_id=$home_id; parent_id=$col_id
            settings=@{ image=@{id=0;url=$sp.avatar}; image_size='thumbnail'; width=@{unit='px';size=160};
                border_radius=@{unit='%';top='50';right='50';bottom='50';left='50'} }
        } -Continue | Out-Null
        Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
            post_id=$home_id; parent_id=$col_id
            settings=@{ title=$sp.name; header_size='h4'; align='center'; title_color='#0F1A2E'
                typography_typography='custom'; typography_font_size=@{unit='px';size=15}; typography_font_weight='600' }
        } -Continue | Out-Null
        Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
            post_id=$home_id; parent_id=$col_id
            settings=@{ editor="<p style='text-align:center;color:#6B7280;font-size:13px;'>$($sp.role)</p>" }
        } -Continue | Out-Null
    }
    Write-Host "  speakers grid built ($($speakers.Count) cards)"

    # ---- GALLERY section ----
    $sec4 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; el_type='container'
        settings=@{ background_background='classic'; background_color='#0F1A2E'; flex_direction='column'; align_items='center'
            padding=@{unit='px';top='80';right='80';bottom='80';left='80';isLinked=$false} }
    }
    $sec4_id = $sec4.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id=$home_id; parent_id=$sec4_id
        settings=@{ editor='<p style="color:#F3E600;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px;">03 — nZEB în imagini</p>' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$home_id; parent_id=$sec4_id
        settings=@{ title='Galerie foto'; header_size='h2'; align='center'; title_color='#FFFFFF'
            typography_typography='custom'; typography_font_size=@{unit='px';size=38}; typography_font_weight='700' }
    } -Continue | Out-Null

    # Gallery grid — 4x3 images via image widget grid
    $galleryUrls = @(
        'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1559223607-a43c990c692c?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1505373877841-8d25f7d46678?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1531058020387-3be344556be6?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1497366216548-37526070297c?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1497366811353-6870744d04b2?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&h=400&fit=crop&sat=-50',
        'https://images.unsplash.com/photo-1551818255-e6e10975bc17?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1591115765373-5207764f72e7?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&h=400&fit=crop&bri=20',
        'https://images.unsplash.com/photo-1582213782179-e0d53f98f2ca?w=600&h=400&fit=crop'
    )

    $galleryItems = $galleryUrls | ForEach-Object { @{ id=0; url=$_ } }
    Invoke-Ability -Name 'stonewright/elementor-add-image-gallery' -InputData @{
        post_id=$home_id; parent_id=$sec4_id
        settings=@{
            wp_gallery = $galleryItems
            gallery_columns = '4'
            image_spacing = 'custom'
            image_spacing_custom = @{ unit='px'; size=12 }
        }
    } -Continue | Out-Null
    Write-Host "  gallery built (12 images)"

    # ---- NEWSLETTER section ----
    $sec5 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id=$home_id; el_type='container'
        settings=@{ background_background='classic'; background_color='#EEF2FF'; flex_direction='column'; align_items='center'
            padding=@{unit='px';top='80';right='80';bottom='80';left='80';isLinked=$false} }
    }
    $sec5_id = $sec5.element_id
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id=$home_id; parent_id=$sec5_id
        settings=@{ editor='<p style="color:#4F46E5;font-size:13px;text-align:center;letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px;">04 — Newsletter</p>' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id=$home_id; parent_id=$sec5_id
        settings=@{ title='Fii la curent cu edițiile viitoare'; header_size='h2'; align='center'; title_color='#0F1A2E'
            typography_typography='custom'; typography_font_size=@{unit='px';size=38}; typography_font_weight='700' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id=$home_id; parent_id=$sec5_id
        settings=@{ editor='<p style="color:#374151;text-align:center;font-size:17px;max-width:680px;margin:0 auto 32px;">Abonează-te la newsletter-ul nostru și primește cele mai recente noutăți despre nZEB Expo, speakerii edițiilor viitoare, programul evenimentului și oportunități pentru expozanți.</p>' }
    } -Continue | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id=$home_id; parent_id=$sec5_id
        settings=@{ text='Abonează-te la newsletter'; align='center'
            background_color='#0F1A2E'; button_text_color='#FFFFFF'
            border_radius=@{unit='px';top='6';right='6';bottom='6';left='6'}
            padding=@{unit='px';top='16';right='32';bottom='16';left='32';isLinked=$false}
            typography_typography='custom'; typography_font_size=@{unit='px';size=16}; typography_font_weight='600'
            link=@{url='#newsletter';is_external=$false;nofollow=$false} }
    } -Continue | Out-Null
    Write-Host "  newsletter built"

    # Set front page via new ability
    $fp = Invoke-Ability -Name 'stonewright/site-set-front-page' -InputData @{ page_id = $home_id } -Continue
    if ($fp) { Write-Host "  front page: $home_id (prev: $($fp.previous_page_id))" }
    else { Write-Host "  (site-set-front-page failed)" -ForegroundColor Yellow }
}

Write-Host ""
Write-Host "==> BUILD COMPLETE (widget failures: $script:wfail)" -ForegroundColor Green
$results | ConvertTo-Json -Depth 3
