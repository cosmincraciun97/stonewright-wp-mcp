# nZ1 live build driver — Phase E.
#
# Builds the Figma "Editie-anterioara" page (node 97:8306) end-to-end at
# http://mcp-test.local using ONLY stonewright/* abilities exposed via the
# WP Abilities REST adapter. Every widget call goes through the new
# Phase C `stonewright/elementor-add-<slug>` catalog. Theme Builder
# header + footer are separate templates (per the user's brief).
#
# Pipeline:
#   1. Create the four navigation menus (top, secondary, footer-col-1,
#      footer-col-2).
#   2. Create the Theme Builder header template + set conditions.
#   3. Create the Theme Builder footer template + set conditions.
#   4. Create the homepage post and populate the five sections:
#        hero / aftermovie / speakers / gallery / newsletter.
#   5. Optionally promote the homepage to be the front page.
#
# All ability invocations use Basic auth with the LocalWP app password
# documented in the project memory.

$ErrorActionPreference = 'Stop'
$script:wfail = 0
$base = 'http://mcp-test.local'
$user = 'admin'
$pass = 'z92d xBLZ HhZy BX4Z qwH3 uWv3'
$cred = [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes("${user}:${pass}"))
$headers = @{ Authorization = "Basic $cred"; 'Content-Type' = 'application/json' }
$results = @{}

function Invoke-Ability {
    param(
        [string]$Name,
        $InputData,
        [int]$Timeout = 60,
        [int]$Retries = 2,
        [switch]$Continue # when set, log + return $null on failure instead of throwing
    )
    $url = "$base/wp-json/wp-abilities/v1/abilities/$Name/run"
    $body = @{ input = $InputData } | ConvertTo-Json -Depth 30 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
    $attempt = 0
    while ($true) {
        $attempt++
        try {
            $r = Invoke-WebRequest -Uri $url -Method POST -Headers $headers -Body $bytes -UseBasicParsing -TimeoutSec $Timeout
            return ($r.Content | ConvertFrom-Json)
        } catch {
            $resp = ''
            if ($_.Exception.Response) {
                try {
                    $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                    $resp = $sr.ReadToEnd()
                } catch {}
            }
            $is_retryable = ($resp -match 'write_failed|opcache|temporarily unavailable')
            if ($is_retryable -and $attempt -le $Retries) {
                Start-Sleep -Milliseconds 250
                continue
            }
            Write-Host "FAIL: $Name (attempt $attempt)" -ForegroundColor Red
            Write-Host "  URL : $url" -ForegroundColor DarkGray
            Write-Host "  Body: $($body.Substring(0, [Math]::Min(200, $body.Length)))" -ForegroundColor DarkGray
            if ($resp) { Write-Host "  Response: $($resp.Substring(0, [Math]::Min(200, $resp.Length)))" -ForegroundColor Red }
            $script:wfail++
            # Default to lenient — log + return $null. The build proceeds with
            # whatever widgets did succeed; final tally counts failures.
            return $null
        }
    }
}

function Step($label, [scriptblock]$body) {
    Write-Host ""
    Write-Host "==> $label" -ForegroundColor Cyan
    & $body
}

# -----------------------------------------------------------------------
# 1. Menus
# -----------------------------------------------------------------------
# Pre-step: delete any prior nz1-* menus from earlier runs so we get a
# clean slate. Stonewright exposes menu-list + menu-delete.
Step "1a. Clean prior menus" {
    try {
        $list = Invoke-Ability -Name 'stonewright/menu-list' -InputData @{}
        foreach ($m in $list.menus) {
            if ($m.name -like 'nz1-*' -or $m.name -like 'probe-*') {
                $mid = if ($m.id) { [int]$m.id } else { [int]$m.menu_id }
                Write-Host "  delete pre-existing menu: $($m.name) (id=$mid)"
                Invoke-Ability -Name 'stonewright/menu-delete' -InputData @{ menu_id = $mid } | Out-Null
            }
        }
    } catch { Write-Host "  (menu-list/delete err — continuing): $($_.Exception.Message)" -ForegroundColor Yellow }
}

Step "1. Create menus" {
    foreach ($menu in @(
        @{ name = 'nz1-top';        location = 'primary'; items = @('Ediții', 'Despre Nzeb Expo', 'Media', 'Noutăți', 'Echipă') },
        @{ name = 'nz1-secondary';  location = ''; items = @('Ediție București 2026', 'Program', 'Speakeri', 'Expozanți', 'Parteneri') },
        @{ name = 'nz1-footer-1';   location = ''; items = @('Despre nZEB Expo', 'Misiune', 'Pentru cine este', 'nZEB Expo în cifre', 'Media Kit & Presă', 'Ediții') },
        @{ name = 'nz1-footer-2';   location = ''; items = @('Program', 'Speakeri', 'Parteneri', 'Informații rapide', 'Hartă eveniment', 'Bilet gratuit', 'Devino expozant') }
    )) {
        Write-Host "  - $($menu.name) ($(($menu.items | Measure-Object).Count) items)"
        $r = Invoke-Ability -Name 'stonewright/menu-create' -InputData @{ name = $menu.name }
        $results[$menu.name] = $r.menu_id
        foreach ($title in $menu.items) {
            Invoke-Ability -Name 'stonewright/menu-add-item' -InputData @{
                menu_id = [int]$r.menu_id
                title = $title
                url = '#'
            } | Out-Null
        }
    }
    Write-Host "  menu IDs:" ($results.GetEnumerator() | Sort-Object Name | ForEach-Object { "$($_.Key)=$($_.Value)" })
}

# -----------------------------------------------------------------------
# 2. Header template (Theme Builder)
# -----------------------------------------------------------------------
Step "2. Header Theme Builder template" {
    $tpl = Invoke-Ability -Name 'stonewright/theme-builder-create-template' -InputData @{
        title         = 'nZ1 Header'
        template_type = 'header'
    }
    $header_id = [int]$tpl.template_id
    Write-Host "  header template_id=$header_id"
    $results['header_id'] = $header_id

    # Document root for the new Theme Builder template — find the auto-
    # created section the template ships with. If empty, create one.
    $tree = Invoke-Ability -Name 'stonewright/elementor-v3-get-page-structure' -InputData @{ post_id = $header_id }
    $root_id = $null
    if ($tree.tree -and $tree.tree.Count -gt 0) {
        $root_id = $tree.tree[0].id
    } else {
        $rootSec = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
            post_id = $header_id
            el_type = 'container'
            settings = @{
                background_background = 'classic'; background_color = '#0F1A2E'
                padding = @{ unit='px'; top='14'; right='40'; bottom='14'; left='40'; isLinked=$false }
            }
        }
        $root_id = $rootSec.element_id
    }
    Write-Host "  root section id=$root_id"

    # Top nav row: 5 links pulled from the nz1-top menu.
    Invoke-Ability -Name 'stonewright/elementor-add-nav-menu' -InputData @{
        post_id   = $header_id
        parent_id = $root_id
        settings  = @{
            menu          = "$($results['nz1-top'])"
            layout        = 'horizontal'
            align_items   = 'center'
            pointer       = 'none'
            menu_typography_typography = 'custom'
            menu_typography_font_size  = @{ unit = 'px'; size = 14 }
            menu_typography_text_transform = 'uppercase'
            color_menu_item       = '#FFFFFF'
            color_menu_item_hover = '#F3E600'
        }
    } | Out-Null

    # Secondary nav row.
    Invoke-Ability -Name 'stonewright/elementor-add-nav-menu' -InputData @{
        post_id   = $header_id
        parent_id = $root_id
        settings  = @{
            menu        = "$($results['nz1-secondary'])"
            layout      = 'horizontal'
            align_items = 'center'
            pointer     = 'underline'
            color_menu_item = '#0F1A2E'
            menu_typography_typography = 'custom'
            menu_typography_font_size  = @{ unit = 'px'; size = 14 }
        }
    } | Out-Null

    # Two CTAs — outline + filled.
    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id   = $header_id
        parent_id = $root_id
        settings  = @{
            text          = 'Devino expozant'
            button_text_color = '#0F1A2E'
            background_color  = '#FFFFFF'
            border_border     = 'solid'
            border_width      = @{ unit = 'px'; top = '1'; right = '1'; bottom = '1'; left = '1' }
            border_color      = '#0F1A2E'
            link              = @{ url = '#'; is_external = $false; nofollow = $false }
        }
    } | Out-Null

    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id   = $header_id
        parent_id = $root_id
        settings  = @{
            text             = 'Obține bilet gratuit'
            button_text_color = '#0F1A2E'
            background_color  = '#F3E600'
            link              = @{ url = '#'; is_external = $false; nofollow = $false }
        }
    } | Out-Null

    # Display conditions: include everywhere.
    Invoke-Ability -Name 'stonewright/theme-builder-set-conditions' -InputData @{
        template_id = $header_id
        conditions  = @( @{ type = 'include'; name = 'general' } )
    } | Out-Null
    Write-Host "  header conditions: include/general"
}

# -----------------------------------------------------------------------
# 3. Footer template (Theme Builder)
# -----------------------------------------------------------------------
Step "3. Footer Theme Builder template" {
    $tpl = Invoke-Ability -Name 'stonewright/theme-builder-create-template' -InputData @{
        title         = 'nZ1 Footer'
        template_type = 'footer'
    }
    $footer_id = [int]$tpl.template_id
    $results['footer_id'] = $footer_id
    Write-Host "  footer template_id=$footer_id"

    $tree = Invoke-Ability -Name 'stonewright/elementor-v3-get-page-structure' -InputData @{ post_id = $footer_id }
    $root_id = $null
    if ($tree.tree -and $tree.tree.Count -gt 0) {
        $root_id = $tree.tree[0].id
    } else {
        $rootSec = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
            post_id = $footer_id
            el_type = 'container'
            settings = @{
                background_background = 'classic'; background_color = '#0F1A2E'
                padding = @{ unit='px'; top='40'; right='40'; bottom='40'; left='40'; isLinked=$false }
            }
        }
        $root_id = $rootSec.element_id
    }

    # Column 1 heading + icon-list.
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{
            title = 'nZEB Expo'; header_size = 'h3'
            title_color = '#FFFFFF'
            typography_typography = 'custom'; typography_font_size = @{ unit='px'; size=22 }
        }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{
            view = 'traditional'; link_click = 'inline'
            icon_list = @(
                @{ text = 'Despre nZEB Expo';   link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Misiune';             link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Pentru cine este';    link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'nZEB Expo în cifre';  link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Media Kit & Presă';   link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Ediții';              link = @{ url='#'; is_external=$false; nofollow=$false } }
            )
            text_color = '#FFFFFF'; icon_color = '#F3E600'
            typography_typography = 'custom'; typography_font_size = @{ unit='px'; size=14 }
        }
    } | Out-Null

    # Column 2 heading + icon-list.
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{ title = 'București 2026'; header_size = 'h3'; title_color = '#FFFFFF'; typography_typography = 'custom'; typography_font_size = @{ unit='px'; size=22 } }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{
            view = 'traditional'; link_click = 'inline'
            icon_list = @(
                @{ text = 'Program';            link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Speakeri';           link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Parteneri';          link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Informații rapide';  link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Hartă eveniment';    link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Bilet gratuit';      link = @{ url='#'; is_external=$false; nofollow=$false } },
                @{ text = 'Devino expozant';    link = @{ url='#'; is_external=$false; nofollow=$false } }
            )
            text_color = '#FFFFFF'; typography_typography = 'custom'; typography_font_size = @{ unit='px'; size=14 }
        }
    } | Out-Null

    # Column 3: Contact + social icons.
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{ title = 'Contact'; header_size = 'h3'; title_color = '#FFFFFF'; typography_typography = 'custom'; typography_font_size = @{ unit='px'; size=22 } }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{
            view = 'traditional'; link_click = 'inline'
            icon_list = @(
                @{ text = 'contact@nzebexpo.ro'; link = @{ url='mailto:contact@nzebexpo.ro' }; selected_icon = @{ value = 'fas fa-envelope'; library = 'fa-solid' } },
                @{ text = '+40 XXX XXX XXX'; link = @{ url='tel:+40000000000' }; selected_icon = @{ value = 'fas fa-phone'; library = 'fa-solid' } }
            )
            text_color = '#FFFFFF'; icon_color = '#F3E600'
        }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-social-icons' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{
            shape = 'rounded'
            icon_size = @{ unit='px'; size=18 }
            social_icon_list = @(
                @{ social_icon = @{ value = 'fab fa-facebook';  library = 'fa-brands' }; link = @{ url='#' } },
                @{ social_icon = @{ value = 'fab fa-instagram'; library = 'fa-brands' }; link = @{ url='#' } },
                @{ social_icon = @{ value = 'fab fa-linkedin';  library = 'fa-brands' }; link = @{ url='#' } },
                @{ social_icon = @{ value = 'fab fa-youtube';   library = 'fa-brands' }; link = @{ url='#' } },
                @{ social_icon = @{ value = 'fab fa-tiktok';    library = 'fa-brands' }; link = @{ url='#' } }
            )
        }
    } | Out-Null

    # Copy strip.
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id = $footer_id; parent_id = $root_id
        settings = @{
            editor = '<p style="text-align:center;color:#94A3B8;font-size:13px;">© 2026 nZEB Expo. Toate drepturile rezervate. &nbsp;|&nbsp; <a href="#" style="color:#F3E600">Politică de confidențialitate</a> &nbsp;|&nbsp; <a href="#" style="color:#F3E600">Termeni și condiții</a> &nbsp;|&nbsp; <a href="#" style="color:#F3E600">Politică cookies</a></p>'
        }
    } | Out-Null

    Invoke-Ability -Name 'stonewright/theme-builder-set-conditions' -InputData @{
        template_id = $footer_id
        conditions  = @( @{ type = 'include'; name = 'general' } )
    } | Out-Null
    Write-Host "  footer conditions: include/general"
}

# -----------------------------------------------------------------------
# 4. Homepage with five sections.
# -----------------------------------------------------------------------
Step "4. Homepage (hero + aftermovie + speakers + gallery + newsletter)" {
    # Create the page.
    $page = Invoke-Ability -Name 'stonewright/content-create-page' -InputData @{
        title  = 'nZEB Expo — Ediție anterioară'
        status = 'publish'
        content = ''
    }
    $home_id = [int]$page.id
    $results['homepage_id'] = $home_id
    Write-Host "  homepage post_id=$home_id"

    # Add an Elementor V3 section to be the canvas root.
    $sec = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $home_id
        el_type = 'container'
        settings = @{
            background_background = 'classic'
            background_color = '#0F1A2E'
            padding = @{ unit='px'; top='80'; right='40'; bottom='80'; left='40'; isLinked=$false }
        }
    }
    $hero_id = $sec.element_id

    # ----- Hero block -----
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $home_id; parent_id = $hero_id
        settings = @{
            title = 'nZEB Expo – Târgul dedicat construcțiilor eficient energetic'
            header_size = 'h1'
            title_color = '#FFFFFF'
            align = 'left'
            typography_typography = 'custom'
            typography_font_size = @{ unit='px'; size=56 }
            typography_font_weight = '700'
            typography_line_height = @{ unit='em'; size=1.1 }
        }
    } | Out-Null

    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id = $home_id; parent_id = $hero_id
        settings = @{
            editor = '<p style="color:#9CA3B7;font-size:18px;line-height:1.6;max-width:600px;">11–14 iunie 2026 · Romexpo, București. 12.500 participanți · 120 expozanți. Standuri profesionale, soluții și produse pentru construcții eficient energetic.</p>'
        }
    } | Out-Null

    Invoke-Ability -Name 'stonewright/elementor-add-icon-list' -InputData @{
        post_id = $home_id; parent_id = $hero_id
        settings = @{
            view = 'inline'
            icon_align = 'left'
            icon_list = @(
                @{ text = 'Romexpo, București'; selected_icon = @{ value='fas fa-map-marker-alt'; library='fa-solid' } },
                @{ text = '11 - 14 iunie 2026';  selected_icon = @{ value='fas fa-calendar';       library='fa-solid' } },
                @{ text = '12.500 participanți'; selected_icon = @{ value='fas fa-users';          library='fa-solid' } },
                @{ text = '120 expozanți';       selected_icon = @{ value='fas fa-store';          library='fa-solid' } }
            )
            text_color = '#FFFFFF'
            icon_color = '#F3E600'
            typography_typography = 'custom'; typography_font_size = @{ unit='px'; size=16 }
        }
    } | Out-Null

    # ----- Aftermovie section -----
    $sec2 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $home_id
        el_type = 'container'
        settings = @{
            background_background = 'classic'
            background_color = '#0A1429'
            padding = @{ unit='px'; top='80'; right='40'; bottom='80'; left='40'; isLinked=$false }
        }
    }
    $sec2_id = $sec2.element_id

    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id = $home_id; parent_id = $sec2_id
        settings = @{ editor = '<p style="color:#F3E600;font-size:14px;text-align:center;letter-spacing:.15em;">01 — AFTERMOVIE</p>' }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $home_id; parent_id = $sec2_id
        settings = @{ title='Atmosfera ediției nZEB Expo București 2025'; header_size='h2'; align='center'; title_color='#FFFFFF'; typography_typography='custom'; typography_font_size=@{ unit='px'; size=42 } }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-image' -InputData @{
        post_id = $home_id; parent_id = $sec2_id
        settings = @{
            image = @{ id = 0; url = 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=1280&h=720&fit=crop' }
            image_size = 'large'
        }
    } | Out-Null

    # ----- Speakers section -----
    $sec3 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $home_id
        el_type = 'container'
        settings = @{
            background_background = 'classic'; background_color = '#FFFFFF'
            padding = @{ unit='px'; top='80'; right='40'; bottom='80'; left='40'; isLinked=$false }
        }
    }
    $sec3_id = $sec3.element_id

    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id = $home_id; parent_id = $sec3_id
        settings = @{ editor = '<p style="color:#0F1A2E;font-size:14px;text-align:center;letter-spacing:.15em;">02 — EXPERȚI DIN INDUSTRIE</p>' }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $home_id; parent_id = $sec3_id
        settings = @{ title='Speakerii evenimentului'; header_size='h2'; align='center'; title_color='#0F1A2E'; typography_typography='custom'; typography_font_size=@{ unit='px'; size=42 } }
    } | Out-Null

    # 4 speaker placeholders via testimonial widgets (which carry image + name + role).
    foreach ($sp in @(
        @{ name='Dr. Andreea Popescu'; role='Cercetător, INCD' },
        @{ name='Eng. Mihai Ionescu';  role='Director Proiectare' },
        @{ name='Arh. Cristina Vasile'; role='Studio nZeb' },
        @{ name='Dr. Bogdan Stoica';   role='Prof. Univ., UTCN' }
    )) {
        Invoke-Ability -Name 'stonewright/elementor-add-testimonial' -InputData @{
            post_id = $home_id; parent_id = $sec3_id
            settings = @{
                testimonial_content = 'Expertiză recunoscută în construcții nZEB.'
                testimonial_name    = $sp.name
                testimonial_job     = $sp.role
                testimonial_image   = @{ id = 0; url = "https://i.pravatar.cc/280?u=$($sp.name -replace ' ', '')" }
            }
        } | Out-Null
    }

    # ----- Gallery section -----
    $sec4 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $home_id
        el_type = 'container'
        settings = @{
            background_background = 'classic'; background_color = '#0F1A2E'
            padding = @{ unit='px'; top='80'; right='40'; bottom='80'; left='40'; isLinked=$false }
        }
    }
    $sec4_id = $sec4.element_id

    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id = $home_id; parent_id = $sec4_id
        settings = @{ editor = '<p style="color:#F3E600;font-size:14px;text-align:center;letter-spacing:.15em;">03 — NZEB ÎN IMAGINI</p>' }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $home_id; parent_id = $sec4_id
        settings = @{ title='Galerie foto'; header_size='h2'; align='center'; title_color='#FFFFFF'; typography_typography='custom'; typography_font_size=@{ unit='px'; size=42 } }
    } | Out-Null

    # Gallery widget — accepts a wp_gallery list.
    $galleryItems = @()
    foreach ($i in 1..8) {
        $galleryItems += @{ id = 0; url = "https://images.unsplash.com/photo-1540575467063-178a50c2df87?w=600&h=600&fit=crop&seed=$i" }
    }
    Invoke-Ability -Name 'stonewright/elementor-add-image-gallery' -InputData @{
        post_id = $home_id; parent_id = $sec4_id
        settings = @{
            wp_gallery = $galleryItems
            gallery_columns = '4'
            image_spacing = 'custom'
            image_spacing_custom = @{ unit='px'; size=16 }
        }
    } | Out-Null

    # ----- Newsletter section -----
    $sec5 = Invoke-Ability -Name 'stonewright/elementor-v3-add-container' -InputData @{
        post_id = $home_id
        el_type = 'container'
        settings = @{
            background_background = 'classic'; background_color = '#F3E600'
            padding = @{ unit='px'; top='80'; right='40'; bottom='80'; left='40'; isLinked=$false }
        }
    }
    $sec5_id = $sec5.element_id

    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id = $home_id; parent_id = $sec5_id
        settings = @{ editor = '<p style="color:#0F1A2E;font-size:14px;text-align:center;letter-spacing:.15em;">04 — NEWSLETTER</p>' }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-heading' -InputData @{
        post_id = $home_id; parent_id = $sec5_id
        settings = @{ title='Fii la curent cu edițiile viitoare'; header_size='h2'; align='center'; title_color='#0F1A2E'; typography_typography='custom'; typography_font_size=@{ unit='px'; size=42 } }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-text-editor' -InputData @{
        post_id = $home_id; parent_id = $sec5_id
        settings = @{ editor = '<p style="color:#0F1A2E;text-align:center;font-size:17px;max-width:780px;margin:0 auto;">Abonează-te la newsletter-ul nostru și primește cele mai recente noutăți despre nZEB Expo, speakerii edițiilor viitoare, programul evenimentului și oportunități pentru expozanți.</p>' }
    } | Out-Null
    Invoke-Ability -Name 'stonewright/elementor-add-button' -InputData @{
        post_id = $home_id; parent_id = $sec5_id
        settings = @{
            text = 'Abonează-te la newsletter'
            align = 'center'
            background_color = '#0F1A2E'
            button_text_color = '#FFFFFF'
            link = @{ url='#newsletter'; is_external=$false; nofollow=$false }
            typography_typography = 'custom'
            typography_font_size = @{ unit='px'; size=18 }
        }
    } | Out-Null

    # Set homepage as front page via raw REST options endpoint (no Stonewright ability for this yet).
    try {
        Invoke-WebRequest -Uri "$base/wp-json/wp/v2/settings" -Method POST -Headers $headers -Body (@{ show_on_front = 'page'; page_on_front = $home_id } | ConvertTo-Json -Compress) -UseBasicParsing -TimeoutSec 10 | Out-Null
        Write-Host "  set front page to $home_id"
    } catch { Write-Host "  (couldn't set front page — homepage still at /?page_id=$home_id)" -ForegroundColor Yellow }

    Write-Host "  homepage built — $home_id"
}

Write-Host ""
Write-Host "==> BUILD COMPLETE (widget failures: $script:wfail)" -ForegroundColor Green
$results | ConvertTo-Json -Depth 3 | Write-Output
