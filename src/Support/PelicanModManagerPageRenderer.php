<?php

namespace MrBytesized\PelicanModManager\Support;

use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use MrBytesized\PelicanModManager\Enums\ModrinthProjectType;

class PelicanModManagerPageRenderer
{
    public static function dynamicStyles(): Closure
    {
        return function (): string {
        $sharedCss = <<<CSS
            /* FORCE TABLE ELEMENTS TO BLOCK LAYOUT */
            .fi-ta-table,
            .fi-ta-table tbody,
            table,
            tbody {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* HIDE COLUMN HEADERS */
            .fi-ta-content thead,
            table thead,
            .fi-ta-table thead,
            thead {
                display: none !important;
            }

            /* REMOVE DEFAULT CONTAINER SHADOWS */
            .fi-ta-content {
                background: transparent !important;
                box-shadow: none !important;
                border: none !important;
            }

            /* EACH ROW AS A CARD */
            .fi-ta-row {
                display: flex !important;
                flex-direction: row !important;
                align-items: center !important;
                justify-content: space-between !important;
                background-color: #1a1a1e !important;
                border: 1px solid #2d2f34 !important;
                border-radius: 12px !important;
                padding: 16px 20px !important;
                margin-bottom: 14px !important;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            .fi-ta-row:hover {
                border-color: #4b4f56 !important;
                background-color: #202024 !important;
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.05) !important;
            }

            /* CELL RESET — block layout so content fills width naturally,
               no dependency on Filament's internal wrapper class names */
            .fi-ta-row > td {
                border: none !important;
                padding: 0 !important;
                background: transparent !important;
                display: block !important;
                height: auto !important;
                min-width: 0 !important;
                box-sizing: border-box !important;
                overflow: visible !important;
            }

            /* Filament's column link/text wrappers — ensure they don't constrain width */
            .fi-ta-row > td > a,
            .fi-ta-row > td > .fi-ta-col,
            .fi-ta-row .fi-ta-text,
            .fi-ta-row .fi-ta-text-item {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* HIDE VISUALLY UNUSED LABELS ON SMALL SCREENS OR HIDDEN TEXT */
            div:has(> .modrinth-custom-styles),
            div:has(> div > .modrinth-custom-styles),
            .fi-in-text:has(.modrinth-custom-styles),
            .fi-in-entry-wrp:has(.modrinth-custom-styles) {
                display: none !important;
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            /* Strip Filament wrapper styling from loading indicator TextEntry */
            div:has(> .pmm-loading-indicator),
            .fi-in-text:has(.pmm-loading-indicator),
            .fi-in-entry-wrp:has(.pmm-loading-indicator) {
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            /* Strip Filament wrapper styling from browse filter bar */
            div:has(> .pmm-browse-bar),
            .fi-in-text:has(.pmm-browse-bar),
            .fi-in-entry-wrp:has(.pmm-browse-bar) {
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                box-shadow: none !important;
            }

            div:has(> .pmm-selection-bar),
            .fi-in-text:has(.pmm-selection-bar),
            .fi-in-entry-wrp:has(.pmm-selection-bar) {
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                box-shadow: none !important;
                min-height: 0 !important;
            }

            .pmm-browse-filter-panel {
                background: #1f2025;
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 12px;
                padding: 8px;
                width: min(292px, calc(100vw - 16px));
                max-height: calc(100vh - 88px);
                overflow: auto;
                box-shadow: 0 16px 44px rgba(0,0,0,0.65);
                scrollbar-width: thin;
                scrollbar-color: #9ca3af transparent;
            }

            .pmm-browse-filter-section {
                background: #25262c;
                border: 1px solid rgba(255,255,255,0.08);
                border-radius: 12px;
                padding: 12px;
            }

            .pmm-browse-filter-section + .pmm-browse-filter-section {
                margin-top: 8px;
            }

            .pmm-browse-filter-title {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                color: #f4f4f5;
                font-size: 15px;
                font-weight: 800;
                margin-bottom: 10px;
            }

            .pmm-browse-facet-row {
                display: flex;
                align-items: center;
                gap: 4px;
                width: 100%;
                min-height: 28px;
                margin: 1px 0;
            }

            .pmm-browse-facet-choice {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                min-width: 0;
                flex: 1;
                min-height: 28px;
                padding: 5px 8px;
                border: 0;
                border-radius: 999px;
                background: transparent;
                color: #9ca3af;
                cursor: pointer;
                font-size: 13px;
                font-weight: 700;
                text-align: left;
                transition: background-color 0.08s ease, color 0.08s ease;
            }

            .pmm-browse-facet-choice:hover {
                background: rgba(255,255,255,0.06);
                color: #d4d4d8;
            }

            .pmm-browse-facet-choice.pmm-browse-facet-active {
                background: #225f3b;
                color: #ffffff;
            }

            .pmm-browse-facet-row.pmm-browse-facet-excluded .pmm-browse-facet-choice {
                background: #6d2d3e;
                color: #ffffff;
            }

            .pmm-browse-facet-left {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                min-width: 0;
            }

            .pmm-browse-facet-left svg {
                width: 14px;
                height: 14px;
                color: currentColor;
                opacity: 0.9;
                flex-shrink: 0;
            }

            .pmm-browse-facet-check {
                width: 14px;
                height: 14px;
                color: #ffffff;
                flex-shrink: 0;
            }

            .pmm-browse-facet-check-hover {
                display: none;
                opacity: 0.75;
            }

            .pmm-browse-facet-choice:hover .pmm-browse-facet-check-hover {
                display: inline-flex;
                color: #1bd96a;
            }

            .pmm-browse-facet-exclude {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
                width: 28px;
                height: 28px;
                border: 0;
                border-radius: 999px;
                background: transparent;
                color: #9ca3af;
                cursor: pointer;
                font-size: 12px;
                font-weight: 800;
                overflow: hidden;
                opacity: 0;
                transition: width 0.1s ease, background-color 0.08s ease, color 0.08s ease;
            }

            .pmm-browse-facet-exclude span {
                display: none;
            }

            .pmm-browse-facet-row:hover .pmm-browse-facet-exclude,
            .pmm-browse-facet-exclude:focus-visible {
                opacity: 1;
            }

            .pmm-browse-facet-exclude:hover,
            .pmm-browse-facet-row.pmm-browse-facet-excluded .pmm-browse-facet-exclude {
                width: 76px;
                background: #7f1d1d;
                color: #ffffff;
                opacity: 1;
            }

            .pmm-browse-facet-exclude:hover span,
            .pmm-browse-facet-row.pmm-browse-facet-excluded .pmm-browse-facet-exclude span {
                display: inline;
            }

            .pmm-browse-filter-toggle {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                width: 100%;
                padding: 8px;
                border: 0;
                border-radius: 10px;
                background: transparent;
                color: #d4d4d8;
                cursor: pointer;
                font-size: 13px;
                font-weight: 800;
                text-align: left;
            }

            .pmm-browse-filter-toggle:hover {
                background: rgba(255,255,255,0.06);
            }

            .pmm-browse-mini-switch {
                width: 36px;
                height: 20px;
                border-radius: 999px;
                padding: 2px;
                background: #3f414a;
                flex-shrink: 0;
                transition: background-color 0.08s ease;
            }

            .pmm-browse-mini-switch::after {
                content: '';
                display: block;
                width: 16px;
                height: 16px;
                border-radius: 999px;
                background: #a1a1aa;
                transition: transform 0.08s ease, background-color 0.08s ease;
            }

            .pmm-browse-mini-switch.pmm-browse-mini-switch-on {
                background: #1bd96a;
            }

            .pmm-browse-mini-switch.pmm-browse-mini-switch-on::after {
                background: #102016;
                transform: translateX(16px);
            }

            /* Hide Filament's built-in table toolbar (search bar + column toggle) for
               ALL tabs — both tabs use their own custom search bars. */
            .fi-ta-header,
            .fi-ta-search,
            .fi-ta-column-toggle,
            .fi-ta-column-manager,
            .fi-ta-col-manager,
            .fi-ta-toggle-columns,
            .fi-ta-table-column-toggle,
            button[aria-label*="column" i],
            button[title*="column" i] {
                display: none !important;
            }

            /* --- SHARED STYLINGS --- */
            .fi-ta-row svg {
                color: #a1a1aa !important;
            }

            .fi-ta-row .fi-btn {
                border-radius: 8px !important;
                padding: 8px 16px !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                text-transform: none !important;
                letter-spacing: normal !important;
                box-shadow: none !important;
                transition: all 0.2s ease !important;
                border: 1px solid transparent !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-success[disabled],
            .fi-ta-row .fi-btn.fi-btn-color-success:disabled {
                background-color: rgba(16, 185, 129, 0.1) !important;
                border: 1px solid rgba(16, 185, 129, 0.2) !important;
                color: #10b981 !important;
                opacity: 0.9 !important;
                cursor: default !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-success:not([disabled]) {
                background-color: #10b981 !important;
                color: #ffffff !important;
                box-shadow: 0 0 12px rgba(16, 185, 129, 0.2) !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-success:not([disabled]):hover {
                background-color: #0d9488 !important;
                box-shadow: 0 0 16px rgba(16, 185, 129, 0.4) !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-warning {
                background-color: #f59e0b !important;
                color: #ffffff !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-warning:hover {
                background-color: #d97706 !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-info,
            .fi-ta-row .fi-btn.fi-btn-color-gray {
                background-color: rgba(255, 255, 255, 0.05) !important;
                border: 1px solid rgba(255, 255, 255, 0.08) !important;
                color: #e4e4e7 !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-info:hover,
            .fi-ta-row .fi-btn.fi-btn-color-gray:hover {
                background-color: rgba(255, 255, 255, 0.1) !important;
                border-color: rgba(255, 255, 255, 0.16) !important;
            }

            .fi-ta-row .fi-btn.fi-btn-color-danger {
                background-color: rgba(239, 68, 68, 0.1) !important;
                border: 1px solid rgba(239, 68, 68, 0.2) !important;
                color: #ef4444 !important;
            }
            .fi-ta-row .fi-btn.fi-btn-color-danger:hover {
                background-color: rgba(239, 68, 68, 0.2) !important;
                border-color: rgba(239, 68, 68, 0.3) !important;
            }
        CSS;

        if ($this->activeTab === 'installed') {
            // td[1]=checkbox, td[2]=Mod, td[3]=Version, td[4]=⇄+Toggle, td[last]=🗑+⋮
            $tabCss = <<<CSS
                /* --- INSTALLED TAB CELLS --- */

                .fi-ta-content {
                    overflow: hidden !important;
                    border: 1px solid #2d2f34 !important;
                    border-radius: 12px !important;
                    background: #202127 !important;
                }

                .fi-ta-row {
                    min-height: 74px !important;
                    padding: 10px 18px !important;
                    margin-bottom: 0 !important;
                    border: 0 !important;
                    border-bottom: 1px solid rgba(255,255,255,0.08) !important;
                    border-radius: 0 !important;
                    background: #202127 !important;
                    box-shadow: none !important;
                    transform: none !important;
                }

                .fi-ta-row:last-child {
                    border-bottom: 0 !important;
                }

                .fi-ta-row:hover {
                    border-color: rgba(255,255,255,0.08) !important;
                    background: #25272d !important;
                    box-shadow: none !important;
                    transform: none !important;
                }

                .fi-ta-row > td:nth-child(2) img[style*='width:72px'],
                .fi-ta-row > td:nth-child(2) div[style*='width:72px'] {
                    width: 48px !important;
                    height: 48px !important;
                    border-radius: 8px !important;
                }

                .fi-ta-row > td:nth-child(2) div[style*='gap:16px'] {
                    gap: 12px !important;
                    padding: 0 !important;
                }

                .fi-ta-main > .fi-ta-header-ctn,
                .fi-ta-main > .fi-ta-selection-indicator {
                    display: none !important;
                }

                .fi-ta input[type='checkbox']:focus,
                .fi-ta input[type='checkbox']:focus-visible {
                    outline: none !important;
                    box-shadow: none !important;
                }

                .fi-ta-row.pmm-selection-cleared,
                .fi-ta-row.pmm-selection-cleared > td,
                .fi-ta-row.pmm-selection-cleared > td::before,
                .fi-ta-row.pmm-selection-cleared > .fi-ta-selection-cell::before,
                .fi-ta-row.pmm-selection-cleared .fi-ta-selection-cell::before {
                    --tw-ring-color: transparent !important;
                    --tw-ring-shadow: 0 0 #0000 !important;
                    box-shadow: none !important;
                    outline: none !important;
                }

                .fi-ta-row.pmm-selection-cleared > td::before,
                .fi-ta-row.pmm-selection-cleared > .fi-ta-selection-cell::before,
                .fi-ta-row.pmm-selection-cleared .fi-ta-selection-cell::before {
                    display: none !important;
                    opacity: 0 !important;
                    background: transparent !important;
                    border-color: transparent !important;
                }

                .pmm-selection-bar {
                    position: fixed;
                    left: 50%;
                    bottom: 18px;
                    transform: translateX(-50%);
                    z-index: 120;
                    display: none;
                    align-items: center;
                    gap: 8px;
                    width: min(900px, calc(100vw - 32px));
                    min-height: 58px;
                    padding: 10px 14px;
                    border: 1px solid rgba(255,255,255,0.14);
                    border-radius: 20px;
                    background: #202127;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.3), 0 10px 24px rgba(0,0,0,0.35);
                }

                .pmm-selection-bar.pmm-selection-bar--active {
                    display: flex;
                }

                .pmm-selection-count {
                    color: #f4f4f5;
                    font-size: 15px;
                    font-weight: 800;
                    white-space: nowrap;
                    font-variant-numeric: tabular-nums;
                }

                .pmm-selection-divider {
                    width: 1px;
                    height: 24px;
                    background: rgba(255,255,255,0.12);
                    margin: 0 8px;
                    flex-shrink: 0;
                }

                .pmm-selection-spacer {
                    flex: 1 1 auto;
                    min-width: 64px;
                }

                .pmm-selection-button {
                    position: relative;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    height: 36px;
                    padding: 0 12px;
                    border: 0;
                    border-radius: 12px;
                    background: transparent;
                    color: #d4d4d8;
                    cursor: pointer;
                    font-size: 14px;
                    font-weight: 700;
                    transition: background-color 0.12s ease, color 0.12s ease;
                }

                .pmm-selection-button svg {
                    width: 17px;
                    height: 17px;
                    flex: 0 0 auto;
                }

                .pmm-selection-button:hover {
                    background: rgba(255,255,255,0.08);
                    color: #ffffff;
                }

                .pmm-selection-button:disabled {
                    opacity: 0.38;
                    cursor: default;
                    pointer-events: none;
                }

                .pmm-selection-menu-wrap {
                    position: relative;
                    display: inline-flex;
                }

                .pmm-selection-menu {
                    position: absolute;
                    left: 0;
                    bottom: calc(100% + 8px);
                    display: none;
                    min-width: 220px;
                    flex-direction: column;
                    gap: 2px;
                    padding: 6px;
                    border: 1px solid rgba(255,255,255,0.12);
                    border-radius: 12px;
                    background: #18191e;
                    box-shadow: 0 14px 34px rgba(0,0,0,0.55);
                }

                .pmm-selection-menu.pmm-selection-menu--open {
                    display: flex;
                }

                .pmm-selection-menu-item {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    width: 100%;
                    min-height: 34px;
                    padding: 0 10px;
                    border: 0;
                    border-radius: 8px;
                    background: transparent;
                    color: #d4d4d8;
                    cursor: pointer;
                    font-size: 13px;
                    font-weight: 700;
                    text-align: left;
                }

                .pmm-selection-menu-item svg {
                    width: 17px;
                    height: 17px;
                    flex: 0 0 auto;
                }

                .pmm-selection-menu-item:hover {
                    background: rgba(255,255,255,0.08);
                    color: #ffffff;
                }

                .pmm-selection-button-danger {
                    color: #f87171;
                }

                .pmm-selection-button-danger:hover {
                    background: #ef4444;
                    color: #ffffff;
                }

                @media (max-width: 640px) {
                    .pmm-selection-bar {
                        left: 12px;
                        right: 12px;
                        transform: none;
                        width: auto !important;
                        overflow-x: auto;
                        font-size: 13px;
                    }

                    .pmm-selection-count {
                        font-size: 13px;
                    }

                    .pmm-selection-spacer {
                        min-width: 24px;
                    }
                }

                /* Checkbox */
                .fi-ta-row > td:first-child:has(input[type='checkbox']) {
                    display: flex !important;
                    flex-shrink: 0 !important;
                    width: auto !important;
                    margin-right: 18px !important;
                    align-items: center !important;
                    justify-content: center !important;
                }

                /* Mod — left half (equal flex with td[4] so Version sits in the middle) */
                .fi-ta-row > td:first-child:not(:has(input[type='checkbox'])),
                .fi-ta-row > td:nth-child(2) {
                    flex: 1 1 0 !important;
                    min-width: 0 !important;
                    align-self: center !important;
                }

                /* Version + filename — fixed-width centre column */
                .fi-ta-row > td:nth-last-child(3) {
                    flex: 0 0 320px !important;
                    width: 320px !important;
                    align-self: center !important;
                    white-space: nowrap !important;
                    overflow: hidden !important;
                }

                /* All right-side controls (⇄/⬇ toggle 🗑 ⋮) — right half, content flushed right */
                .fi-ta-row > td:nth-last-child(2) {
                    flex: 1 1 0 !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: flex-end !important;
                    gap: 4px !important;
                    overflow: visible !important;
                }
                .fi-ta-row > td:has([data-pmm-filename]) {
                    flex: 1 1 0 !important;
                    display: flex !important;
                    align-items: center !important;
                    justify-content: flex-end !important;
                    gap: 4px !important;
                    overflow: visible !important;
                }
                /* Override shared block layout inside the actions cell so the
                   flex container from formatStateUsing renders horizontally */
                .fi-ta-row > td:nth-last-child(2) .fi-ta-col,
                .fi-ta-row > td:nth-last-child(2) .fi-ta-text,
                .fi-ta-row > td:nth-last-child(2) .fi-ta-text-item,
                .fi-ta-row > td:nth-last-child(2) > a {
                    display: contents !important;
                    width: auto !important;
                }
                .fi-ta-row > td:has([data-pmm-filename]) .fi-ta-col,
                .fi-ta-row > td:has([data-pmm-filename]) .fi-ta-text,
                .fi-ta-row > td:has([data-pmm-filename]) .fi-ta-text-item,
                .fi-ta-row > td:has([data-pmm-filename]) > a {
                    display: contents !important;
                    width: auto !important;
                }
                .fi-ta-row .pmm-toggle-switch {
                    display: inline-flex !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                    position: relative !important;
                    flex: 0 0 46px !important;
                    width: 46px !important;
                    min-width: 46px !important;
                    height: 24px !important;
                    min-height: 24px !important;
                    padding: 0 !important;
                    margin: 0 4px !important;
                    border: 0 !important;
                    border-radius: 9999px !important;
                    background: var(--pmm-toggle-bg, #2f333d) !important;
                    appearance: none !important;
                    -webkit-appearance: none !important;
                    transition: background-color 0.16s ease, opacity 0.16s ease !important;
                }
                .fi-ta-row .pmm-toggle-switch__knob {
                    display: block !important;
                    position: absolute !important;
                    left: 3px !important;
                    top: 3px !important;
                    width: 18px !important;
                    height: 18px !important;
                    border-radius: 9999px !important;
                    background: var(--pmm-toggle-knob-bg, #9aa4b2) !important;
                    transform: translateX(var(--pmm-toggle-x, 0px)) !important;
                    transition: transform 0.16s ease, background-color 0.16s ease !important;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.35) !important;
                }
                .fi-ta-row .pmm-toggle-switch:disabled {
                    cursor: wait !important;
                    opacity: 0.65 !important;
                }
                @keyframes pmm-spin {
                    to { transform: rotate(360deg); }
                }
                .pmm-spin {
                    animation: pmm-spin 0.7s linear infinite !important;
                    transform-origin: center !important;
                }

                /* Filament actions td — hidden (actions are rendered inside td[4] HtmlString) */
                .fi-ta-row > td:has(.fi-ta-actions),
                .fi-ta-row > td:has(.fi-ac) {
                    display: none !important;
                }

                /* Disabled-row grayscale */
                .pmm-row-disabled {
                    filter: none !important;
                    opacity: 1 !important;
                }
                .pmm-row-disabled > td:not(.fi-ta-selection-cell) {
                    filter: grayscale(1) !important;
                    opacity: 0.45 !important;
                }

                /* --- COLUMN HEADERS (thead) --- */
                /* Override the global thead:none from shared CSS */
                .fi-ta-content thead,
                table thead,
                .fi-ta-table thead,
                thead {
                    display: block !important;
                }
                thead tr {
                    display: flex !important;
                    align-items: center !important;
                    padding: 0 18px !important;
                    height: 48px !important;
                    margin-bottom: 0 !important;
                    border-bottom: 1px solid rgba(255,255,255,0.08) !important;
                    background: #24262c !important;
                }
                thead th {
                    display: block !important;
                    font-size: 13px !important;
                    font-weight: 600 !important;
                    color: #a1a1aa !important;
                    letter-spacing: 0.04em !important;
                    text-transform: uppercase !important;
                    padding: 0 !important;
                    border: none !important;
                    background: transparent !important;
                    white-space: nowrap !important;
                }
                /* Match card column widths exactly */
                thead th:first-child {
                    flex-shrink: 0 !important;
                    width: auto !important;
                    margin-right: 18px !important;
                }
                thead th:nth-child(2) { flex: 1 1 0 !important; }
                thead th:nth-child(3) { flex: 0 0 320px !important; width: 320px !important; text-align: center !important; }
                thead th:nth-child(4) { flex: 1 1 0 !important; text-align: right !important; }
                thead th:last-child   { display: none !important; }
                /* Filament renders sort headers as buttons — keep their text styled */
                thead th button, thead th span {
                    font-size: 13px !important;
                    font-weight: 600 !important;
                    color: #a1a1aa !important;
                    letter-spacing: 0.04em !important;
                    text-transform: uppercase !important;
                    background: none !important;
                    border: none !important;
                    padding: 0 !important;
                    cursor: default !important;
                }

                /* --- FILTER BAR wrapper stripping --- */
                div:has(> .pmm-filter-bar),
                .fi-in-text:has(.pmm-filter-bar),
                .fi-in-entry-wrp:has(.pmm-filter-bar) {
                    border: none !important;
                    background: transparent !important;
                    padding: 0 !important;
                    box-shadow: none !important;
                }
            CSS;
        } else {
            // Browse Mods tab ('all')
            // The HtmlString owns the entire card layout including the right panel
            // (Versions button + Install/Installed/Update button + stats).
            // The Filament actions td is hidden — actions are triggered via mountTableAction.
            $tabCss = <<<CSS
                /* --- BROWSE TAB CELLS --- */

                /* Row — stretch so right panel can align stats to the bottom */
                .fi-ta-row {
                    align-items: stretch !important;
                }

                /* Title td — takes all remaining space */
                .fi-ta-row > td:first-child {
                    flex: 1 !important;
                    min-width: 0 !important;
                }

                /* Unused data columns + actions td — all hidden */
                .fi-ta-row > td:nth-child(2),
                .fi-ta-row > td:nth-child(3),
                .fi-ta-row > td:last-child {
                    display: none !important;
                }
            CSS;
        }

        return $sharedCss . $tabCss;
        };
    }

    public static function installedSelectionBarScript(): Closure
    {
        return function (): string {
        return <<<'JS'
            (() => {
                const controller = {
                    version: 3,
                    selected: [],
                    getBars() {
                        return Array.from(document.querySelectorAll('[data-pmm-selection-bar]'));
                    },
                    getItems(bar) {
                        if (!bar) return {};
                        const encoded = bar.getAttribute('data-pmm-items') || 'e30=';
                        if (bar._pmmItemsEncoded !== encoded) {
                            try {
                                bar._pmmItems = JSON.parse(atob(encoded));
                            } catch (error) {
                                bar._pmmItems = {};
                            }
                            bar._pmmItemsEncoded = encoded;
                        }
                        return bar._pmmItems || {};
                    },
                    getSelectedIds() {
                        return Array.from(document.querySelectorAll('.fi-ta input[type=checkbox]:checked'))
                            .filter((box) => !box.closest('thead'))
                            .map((box) => box.value || box.getAttribute('value') || '')
                            .filter((value) => value && value !== 'on')
                            .filter((value, index, values) => values.indexOf(value) === index);
                    },
                    getSelectedItems(bar) {
                        const items = this.getItems(bar);
                        return this.selected.map((id) => items[id]).filter(Boolean);
                    },
                    getWire(bar) {
                        const livewire = window.Livewire;
                        if (!livewire?.find) return null;

                        const ids = [];
                        const pageId = bar?.getAttribute?.('data-pmm-page-wire-id');
                        if (pageId) ids.push(pageId);
                        const nearestId = bar?.closest?.('[wire\\:id]')?.getAttribute?.('wire:id');
                        if (nearestId) ids.push(nearestId);

                        document.querySelectorAll('[wire\\:id]').forEach((node) => {
                            const id = node.getAttribute('wire:id');
                            if (id && !ids.includes(id)) ids.push(id);
                        });

                        let fallback = null;
                        for (const id of ids) {
                            const component = livewire.find(id);
                            if (!component) continue;

                            fallback ??= component;

                            const data = component.canonical || component.ephemeral || component.serverMemo?.data || component.snapshot?.data || {};
                            if (
                                Object.prototype.hasOwnProperty.call(data, 'installedBulkSelectionJson')
                                || Object.prototype.hasOwnProperty.call(data, 'installedSearch')
                                || Object.prototype.hasOwnProperty.call(data, 'activeTab')
                            ) {
                                return component;
                            }
                        }

                        return fallback;
                    },
                    call(bar, method, ...args) {
                        const component = this.getWire(bar);
                        if (!component) return Promise.resolve(null);
                        const promise = typeof component.call === 'function'
                            ? component.call(method, ...args)
                            : (typeof component.$wire?.$call === 'function' ? component.$wire.$call(method, ...args) : null);

                        return Promise.resolve(promise).catch((error) => {
                            console.error('Pelican Mod Manager selection action failed', method, error);
                            return null;
                        });
                    },
                    fireEvent(bar, event, payload = {}) {
                        const component = this.getWire(bar);
                        if (!component) return Promise.resolve(null);
                        const promise = typeof component.dispatch === 'function'
                            ? component.dispatch(event, payload)
                            : (typeof component.$wire?.$dispatch === 'function' ? component.$wire.$dispatch(event, payload) : null);

                        return Promise.resolve(promise).catch((error) => {
                            console.error('Pelican Mod Manager selection event failed', event, error);
                            return null;
                        });
                    },
                    set(bar, property, value) {
                        const component = this.getWire(bar);
                        if (!component) return Promise.resolve(null);
                        if (typeof component.set === 'function') return Promise.resolve(component.set(property, value));
                        if (typeof component.$wire?.$set === 'function') return Promise.resolve(component.$wire.$set(property, value));
                        return this.call(bar, 'set', property, value);
                    },
                    projectUrl(item) {
                        if (!item || item.is_local || !item.slug) return '';
                        return 'https://modrinth.com/' + (item.project_type || 'mod') + '/' + item.slug;
                    },
                    copyShare(bar, format) {
                        const lines = this.getSelectedItems(bar).map((item) => {
                            const title = item.title || 'Selected mod';
                            const filename = item.filename || title;
                            const url = this.projectUrl(item);

                            if (format === 'names') return title;
                            if (format === 'files') return filename;
                            if (format === 'links') return url || title;
                            if (format === 'markdown') return url ? '[' + title + '](' + url + ')' : title;

                            return title;
                        }).filter(Boolean);

                        navigator.clipboard?.writeText(lines.join('\n'));
                    },
                    setMenuOpen(bar, open) {
                        bar?.querySelector('[data-pmm-selection-menu]')?.classList.toggle('pmm-selection-menu--open', open);
                    },
                    getSelectedBoxes() {
                        return Array.from(document.querySelectorAll('.fi-ta input[type=checkbox]:checked'))
                            .filter((box) => !box.closest('thead'));
                    },
                    refresh() {
                        this.selected = this.getSelectedIds();
                        const count = this.selected.length;

                        this.getBars().forEach((bar) => {
                            const items = this.getSelectedItems(bar);
                            const allEnabled = items.length > 0 && items.every((item) => !item.is_disabled);
                            const allDisabled = items.length > 0 && items.every((item) => item.is_disabled);

                            bar.classList.toggle('pmm-selection-bar--active', count > 0);
                            bar.querySelector('[data-pmm-selection-count]').textContent =
                                count === 1 ? '1 mod selected' : count + ' mods selected';
                            bar.querySelector('[data-pmm-selection-action="enable"]').disabled = allEnabled;
                            bar.querySelector('[data-pmm-selection-action="disable"]').disabled = allDisabled;

                            if (count === 0) {
                                this.setMenuOpen(bar, false);
                            }
                        });
                    },
                    markSelected(bar, enabled) {
                        const items = this.getItems(bar);
                        this.selected.forEach((id) => {
                            if (items[id]) items[id].is_disabled = !enabled;
                        });
                        this.refresh();
                    },
                    applyBulkVisualState(enabled) {
                        const selectedBoxes = this.getSelectedBoxes();
                        selectedBoxes.forEach((box) => {
                            const row = box.closest('.fi-ta-row, tr');
                            if (!row) return;

                            row.classList.remove('fi-selected');
                            row.classList.add('pmm-selection-cleared');
                            row.querySelectorAll('.fi-selected').forEach((node) => node.classList.remove('fi-selected'));
                            row.classList.toggle('pmm-row-disabled', !enabled);
                            row.style.filter = '';
                            row.style.opacity = '';
                            row.querySelectorAll('td').forEach((cell) => {
                                if (cell.classList.contains('fi-ta-selection-cell')) {
                                    cell.style.filter = '';
                                    cell.style.opacity = '';
                                    return;
                                }
                                cell.style.filter = enabled ? '' : 'grayscale(1)';
                                cell.style.opacity = enabled ? '' : '0.45';
                            });

                            const toggle = row.querySelector('.pmm-toggle-switch');
                            if (toggle) {
                                const oldFilename = toggle.dataset.pmmFilename || '';
                                const newFilename = enabled
                                    ? oldFilename.replace(/\.disabled$/i, '')
                                    : (oldFilename.match(/\.disabled$/i) ? oldFilename : oldFilename + '.disabled');

                                toggle.dataset.pmmFilename = newFilename;
                                toggle.style.setProperty('--pmm-toggle-bg', enabled ? '#1BD96A' : '#2f333d');
                                toggle.style.setProperty('--pmm-toggle-knob-bg', enabled ? '#03150A' : '#9aa4b2');
                                toggle.style.setProperty('--pmm-toggle-x', enabled ? '22px' : '0px');

                                const alpine = window.Alpine?.$data ? window.Alpine.$data(toggle) : null;
                                if (alpine && Object.prototype.hasOwnProperty.call(alpine, 'on')) {
                                    alpine.on = enabled;
                                    alpine.busy = false;
                                }
                            }

                            box.checked = false;
                            box.indeterminate = false;
                            box.removeAttribute('checked');
                            box.setAttribute('aria-checked', 'false');
                            box.blur();
                            box.dispatchEvent(new Event('change', { bubbles: true }));
                        });

                        document.querySelectorAll('.fi-ta thead input[type=checkbox]:checked, .fi-ta thead input[type=checkbox]').forEach((box) => {
                            box.checked = false;
                            box.indeterminate = false;
                            box.removeAttribute('checked');
                            box.setAttribute('aria-checked', 'false');
                            box.blur();
                            box.dispatchEvent(new Event('change', { bubbles: true }));
                        });

                        this.selected = [];
                        this.refresh();
                    },
                    bind() {
                        if (this.bound) return;
                        this.bound = true;

                        document.addEventListener('change', (event) => {
                            if (event.target?.matches?.('.fi-ta input[type=checkbox]')) {
                                const row = event.target.closest('.fi-ta-row, tr');
                                if (event.target.checked) {
                                    row?.classList.remove('pmm-selection-cleared');
                                }
                                window.requestAnimationFrame(() => this.refresh());
                            }
                        });

                        document.addEventListener('click', (event) => {
                            const bar = event.target?.closest?.('[data-pmm-selection-bar]');

                            this.getBars().forEach((candidate) => {
                                if (candidate !== bar) this.setMenuOpen(candidate, false);
                            });

                            if (!bar) return;

                            const shareFormat = event.target.closest('[data-pmm-selection-share]')?.getAttribute('data-pmm-selection-share');
                            if (shareFormat) {
                                event.preventDefault();
                                if (shareFormat === 'export') {
                                    this.setMenuOpen(bar, false);
                                    this.call(bar, 'exportSelectedModpack', this.selected);
                                    return;
                                }

                                this.copyShare(bar, shareFormat);
                                this.setMenuOpen(bar, false);
                                return;
                            }

                            const action = event.target.closest('[data-pmm-selection-action]')?.getAttribute('data-pmm-selection-action');
                            if (!action) return;

                            event.preventDefault();

                            if (action === 'share') {
                                const menu = bar.querySelector('[data-pmm-selection-menu]');
                                this.setMenuOpen(bar, !menu?.classList.contains('pmm-selection-menu--open'));
                                return;
                            }

                            if (action === 'clear') {
                                this.call(bar, 'clearInstalledSelection');
                                this.call(bar, 'setInstalledBulkSelection', '[]');
                                document.querySelectorAll('.fi-ta input[type=checkbox]:checked, .fi-ta input[type=checkbox]').forEach((box) => {
                                    const row = box.closest('.fi-ta-row, tr');
                                    row?.classList.remove('fi-selected');
                                    row?.classList.add('pmm-selection-cleared');
                                    box.checked = false;
                                    box.indeterminate = false;
                                    box.removeAttribute('checked');
                                    box.setAttribute('aria-checked', 'false');
                                    box.blur();
                                    box.dispatchEvent(new Event('change', { bubbles: true }));
                                });
                                this.refresh();
                                return;
                            }

                            if (action === 'enable' || action === 'disable') {
                                const enabled = action === 'enable';
                                const selected = [...this.selected];
                                this.markSelected(bar, enabled);
                                this.applyBulkVisualState(enabled);
                                this.call(bar, 'setSelectedInstalledModsEnabled', selected, enabled);
                                return;
                            }

                            if (action === 'delete' && confirm('Uninstall selected mods?')) {
                                this.call(bar, 'uninstallInstalledModsByIds', this.selected);
                            }
                        });

                        new MutationObserver(() => window.requestAnimationFrame(() => this.refresh()))
                            .observe(document.body, { childList: true, subtree: true });
                        document.addEventListener('livewire:navigated', () => this.refresh());
                        document.addEventListener('livewire:updated', () => this.refresh());
                    },
                };

                window.pmmSelectionBarController = controller;
                controller.bind();
                window.requestAnimationFrame(() => controller.refresh());
            })();
        JS;
        };
    }

    public static function installedSelectionBar(): Closure
    {
        return function (): string {
        /** @var Server $server */
        $server = Filament::getTenant();
        $type = ModrinthProjectType::fromServer($server);
        $items = $type
            ? cache()->get("modrinth_installed_resolved_list_" . $server->uuid, $this->getMetadataOnlyList())
            : $this->getMetadataOnlyList();
        $itemMap = collect(is_array($items) ? $items : [])
            ->mapWithKeys(fn ($item) => [
                (string)($item['project_id'] ?? '') => [
                    'title' => $item['title'] ?? 'Selected mod',
                    'filename' => $item['filename'] ?? '',
                    'slug' => $item['slug'] ?? '',
                    'project_type' => $item['project_type'] ?? 'mod',
                    'is_disabled' => !empty($item['is_disabled']),
                    'is_local' => !empty($item['is_local']),
                ],
            ])
            ->filter(fn ($item, $key) => $key !== '')
            ->toArray();
        $itemsJson = base64_encode(json_encode($itemMap) ?: '{}');

        $shareSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='18' cy='5' r='3'/><circle cx='6' cy='12' r='3'/><circle cx='18' cy='19' r='3'/><path d='M8.59 13.51l6.83 3.98M15.41 6.51L8.59 10.49'/></svg>";
        $enableSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M12 2v10'/><path d='M18.4 6.6a9 9 0 1 1-12.8 0'/></svg>";
        $disableSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M12 2v10'/><path d='M18.4 6.6a9 9 0 0 1 1.1 11.3'/><path d='M5.6 6.6a9 9 0 0 0 11.6 13.6'/><path d='M2 2l20 20'/></svg>";
        $trashSvg = "<svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6'/></svg>";
        $projectNamesSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M12 20h-1a2 2 0 0 1-2-2 2 2 0 0 1-2 2H6M13 8h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-7M5 16H4a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h1M6 4h1a2 2 0 0 1 2 2 2 2 0 0 1 2-2h1M9 6v12'/></svg>";
        $fileNamesSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5z'/><path d='M14 2v6h6'/></svg>";
        $projectLinksSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/><path d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/></svg>";
        $markdownLinksSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m16 18 6-6-6-6M8 6l-6 6 6 6'/></svg>";
        $exportSvg = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='7 10 12 15 17 10'/><line x1='12' y1='15' x2='12' y2='3'/></svg>";
        $pageWireId = method_exists($this, 'getId') ? e((string) $this->getId()) : '';

        return <<<HTML
            <div
                class="pmm-selection-bar"
                data-pmm-selection-bar
                data-pmm-page-wire-id="{$pageWireId}"
                data-pmm-items="{$itemsJson}"
                role="toolbar"
                aria-label="Selection actions"
            >
                <span class="pmm-selection-count" data-pmm-selection-count>0 mods selected</span>
                <div class="pmm-selection-divider"></div>
                <button type="button" class="pmm-selection-button" data-pmm-selection-action="clear">
                    <span>Clear</span>
                </button>
                <div class="pmm-selection-spacer"></div>
                <div class="pmm-selection-menu-wrap">
                    <button type="button" class="pmm-selection-button" data-pmm-selection-action="share">
                        {$shareSvg}<span>Share</span>
                    </button>
                    <div class="pmm-selection-menu" data-pmm-selection-menu>
                        <button type="button" class="pmm-selection-menu-item" data-pmm-selection-share="names">{$projectNamesSvg}<span>Project names</span></button>
                        <button type="button" class="pmm-selection-menu-item" data-pmm-selection-share="files">{$fileNamesSvg}<span>File names</span></button>
                        <button type="button" class="pmm-selection-menu-item" data-pmm-selection-share="links">{$projectLinksSvg}<span>Project links</span></button>
                        <button type="button" class="pmm-selection-menu-item" data-pmm-selection-share="markdown">{$markdownLinksSvg}<span>Markdown links</span></button>
                        <button type="button" class="pmm-selection-menu-item" data-pmm-selection-share="export">{$exportSvg}<span>Export as modpack</span></button>
                    </div>
                </div>
                <button type="button" class="pmm-selection-button" data-pmm-selection-action="enable">
                    {$enableSvg}<span>Enable</span>
                </button>
                <button type="button" class="pmm-selection-button" data-pmm-selection-action="disable">
                    {$disableSvg}<span>Disable</span>
                </button>
                <div class="pmm-selection-divider"></div>
                <button type="button" class="pmm-selection-button pmm-selection-button-danger"
                    data-pmm-selection-action="delete">
                    {$trashSvg}<span>Delete</span>
                </button>
            </div>
        HTML;
        };
    }

    public static function installedFilterBar(): Closure
    {
        return function (): string {
        $cur         = $this->installedStatusFilter;
        $hasDisabled = $this->installedHasDisabled;
        $hasUpdates  = $this->installedHasUpdates;

        /** @var Server $server */
        $server   = Filament::getTenant();
        $modType  = ModrinthProjectType::fromServer($server);
        $folderUrl = $modType ? e(ListFiles::getUrl(['path' => $modType->getFolder()])) : '#';

        // ── Lazy update-check trigger — fires once after render, uses server-side cache ──
        // Uses the public installedUpdatesChecked flag to avoid redundant AJAX after first check.
        $lazyCheck = "<div x-data x-init=\"\$nextTick(() => { if (!\$wire.installedEnriched) \$wire.call('checkInstalledUpdates'); })\" style='display:none'></div>";

        // ── Row 1: search input (left, wide) + Open folder + Upload files (right) ──
        $searchSvg = "<svg style='position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#6b7280;flex-shrink:0;pointer-events:none;' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='11' cy='11' r='8'/><line x1='21' y1='21' x2='16.65' y2='16.65'/></svg>";
        $searchInput = "<div style='flex:1;position:relative;'>"
            . $searchSvg
            . "<input type='text' wire:model.live.debounce.300ms='installedSearch' placeholder='Search installed mods...' "
            . "style='width:100%;padding:9px 14px 9px 38px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#f3f4f6;font-size:14px;outline:none;box-sizing:border-box;transition:border-color 0.15s ease;' "
            . "onfocus=\"this.style.borderColor='rgba(27,217,106,0.4)'\" onblur=\"this.style.borderColor='rgba(255,255,255,0.1)'\"/>"
            . "</div>";

        $hBtnBase  = "display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer;border:1px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.04);color:#e4e4e7;white-space:nowrap;text-decoration:none;transition:background 0.15s ease;box-sizing:border-box;";
        $hBtnHov   = " onmouseover=\"this.style.background='rgba(255,255,255,0.09)'\" onmouseout=\"this.style.background='rgba(255,255,255,0.04)'\"";
        $uploadSvg = "<svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='16 16 12 12 8 16'/><line x1='12' y1='12' x2='12' y2='21'/><path d='M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3'/></svg>";
        $exportSvg = "<svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='7 10 12 15 17 10'/><line x1='12' y1='15' x2='12' y2='3'/></svg>";
        $folderSvg = "<svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'/></svg>";
        $row1 = "<div style='display:flex;align-items:center;gap:10px;margin-bottom:10px;'>"
            . $searchInput
            . "<button type='button' wire:click=\"openUploadModal\" style=\"{$hBtnBase}\"{$hBtnHov}>{$uploadSvg}Upload files</button>"
            . "<button type='button' wire:click=\"mountAction('export_modpack')\" style=\"{$hBtnBase}\"{$hBtnHov}>{$exportSvg}Export modpack</button>"
            . "<a href=\"{$folderUrl}\" style=\"{$hBtnBase}\"{$hBtnHov}>{$folderSvg}View folder</a>"
            . "</div>";

        // ── Row 2: filter icon + chips + sort (left) | Update all + Refresh (right) ──
        $chipBase   = "display:inline-flex;align-items:center;padding:5px 14px;border-radius:9999px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid;transition:all 0.15s ease;background:none;";
        $chipActive = $chipBase . "border-color:rgba(27,217,106,0.5);color:#1bd96a;background:rgba(27,217,106,0.1);";
        $chipOff    = $chipBase . "border-color:rgba(255,255,255,0.1);color:#a1a1aa;";

        $filterIcon = "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='#6b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polygon points='22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3'/></svg>";

        $chips = '';
        foreach (['all' => 'All', 'updates' => 'Updates', 'enabled' => 'Enabled', 'disabled' => 'Disabled'] as $key => $label) {
            if ($key === 'updates' && !$hasUpdates) continue;
            if (($key === 'enabled' || $key === 'disabled') && !$hasDisabled) continue;
            $style = $cur === $key ? $chipActive : $chipOff;
            $hov   = $cur !== $key ? " onmouseover=\"this.style.color='#ffffff';this.style.borderColor='rgba(255,255,255,0.25)'\" onmouseout=\"this.style.color='#a1a1aa';this.style.borderColor='rgba(255,255,255,0.1)'\"" : '';
            $chips .= "<button type='button' wire:click=\"setInstalledFilter('{$key}')\" style=\"{$style}\"{$hov}>{$label}</button>";
        }

        // Sort dropdown — uses x-teleport='body' so it appears above the table regardless of stacking context
        $sortLabels = ['alpha_asc' => 'Alphabetical A→Z', 'alpha_desc' => 'Alphabetical Z→A', 'newest' => 'Newest first', 'oldest' => 'Oldest first'];
        $curSortLabel = $sortLabels[$this->installedSortMode] ?? 'Alphabetical A→Z';
        $listSvg  = "<svg width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><line x1='8' y1='6' x2='21' y2='6'/><line x1='8' y1='12' x2='21' y2='12'/><line x1='8' y1='18' x2='21' y2='18'/><line x1='3' y1='6' x2='3.01' y2='6'/><line x1='3' y1='12' x2='3.01' y2='12'/><line x1='3' y1='18' x2='3.01' y2='18'/></svg>";
        $chevSvg  = "<svg width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>";
        $checkSvg = "<svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='#1bd96a' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'/></svg>";
        $sortOpts = '';
        foreach ($sortLabels as $k => $lbl) {
            $active = $this->installedSortMode === $k;
            $icon   = $active ? $checkSvg : "<span style='width:12px;display:inline-block'></span>";
            $color  = $active ? '#1bd96a' : '#e4e4e7';
            $sortOpts .= "<button type='button' wire:click=\"setInstalledSort('{$k}')\" x-on:click=\"open=false\" "
                . "style='display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:6px;font-size:13px;font-weight:500;color:{$color};background:transparent;border:none;cursor:pointer;white-space:nowrap;text-align:left;' "
                . "onmouseover=\"this.style.background='rgba(255,255,255,0.07)'\" onmouseout=\"this.style.background='transparent'\">"
                . $icon . e($lbl) . "</button>";
        }
        // .stop prevents the click bubbling to document so click.away on the teleported panel doesn't misfire
        $sortDropdown = "<div x-data=\"{ open:false, py:0, px:0 }\" style='display:inline-flex;'>"
            . "<button type='button' x-ref='sortbtn' "
            . "x-on:click.stop=\"if(!open){let r=\$refs.sortbtn.getBoundingClientRect();py=r.bottom+6;px=r.left;} open=!open\" "
            . "style='display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:9999px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid rgba(255,255,255,0.1);color:#a1a1aa;background:none;transition:all 0.15s ease;' "
            . "onmouseover=\"this.style.color='#ffffff';this.style.borderColor='rgba(255,255,255,0.25)'\" onmouseout=\"this.style.color='#a1a1aa';this.style.borderColor='rgba(255,255,255,0.1)'\">"
            . $listSvg . " " . e($curSortLabel) . " " . $chevSvg
            . "</button>"
            . "<template x-teleport='body'>"
            . "<div x-show='open' x-cloak x-on:click.away='open=false' "
            . ":style=\"'position:fixed;top:'+py+'px;left:'+px+'px;background:#18181b;border:1px solid #3f3f46;border-radius:10px;padding:4px;min-width:185px;z-index:9999;box-shadow:0 12px 32px rgba(0,0,0,0.6);'\">"
            . $sortOpts
            . "</div></template></div>";

        $leftGroup = "<div style='display:flex;gap:6px;align-items:center;flex-wrap:wrap;'>"
            . "<span style='display:inline-flex;align-items:center;margin-right:2px;'>{$filterIcon}</span>"
            . $chips . $sortDropdown . "</div>";

        $rightBtns = '';
        if ($hasUpdates) {
            $rightBtns .= "<button type='button' wire:click='updateAllMods' "
                . "style='display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:#1bd96a;color:#03150a;border:none;transition:opacity 0.15s ease;' "
                . "onmouseover=\"this.style.opacity='0.85'\" onmouseout=\"this.style.opacity='1'\">"
                . "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='23 4 23 10 17 10'/><path d='M20.49 15a9 9 0 1 1-2.12-9.36L23 10'/></svg>"
                . "Update all</button>";
        }
        $rightBtns .= "<button type='button' wire:click='refreshInstalled' "
            . "wire:loading.attr='disabled' wire:target='refreshInstalled' "
            . "style='display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;background:transparent;color:#a1a1aa;border:1px solid rgba(255,255,255,0.12);transition:all 0.15s ease;' "
            . "onmouseover=\"this.style.color='#ffffff';this.style.borderColor='rgba(255,255,255,0.25)'\" onmouseout=\"this.style.color='#a1a1aa';this.style.borderColor='rgba(255,255,255,0.12)'\">"
            . "<svg class='pmm-refresh-icon' wire:loading.class='pmm-spin' wire:target='refreshInstalled' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='1 4 1 10 7 10'/><path d='M3.51 15a9 9 0 1 0 .49-4.95L1 10'/></svg>"
            . "Refresh</button>";

        $row2 = "<div style='display:flex;align-items:center;justify-content:space-between;'>"
            . $leftGroup
            . "<div style='display:flex;gap:8px;align-items:center;'>{$rightBtns}</div>"
            . "</div>";

        return "<div class='pmm-filter-bar' style='padding:0 0 8px 0;'>{$lazyCheck}{$row1}{$row2}</div>";
    }

    public function setInstalledFilter(string $filter): void
    {
        $this->installedStatusFilter = $filter;
    }

    public function setInstalledSort(string $mode): void
    {
        $allowed = ['alpha_asc', 'alpha_desc', 'newest', 'oldest'];
        if (in_array($mode, $allowed, true)) {
            $this->installedSortMode = $mode;
        }
    }

    public function setBrowseSort(string $mode): void
    {
        $allowed = ['relevance', 'downloads', 'follows', 'newest', 'updated'];
        if (in_array($mode, $allowed, true)) {
            $this->browseSortMode = $mode;
            $this->setBrowsePage(1);
        }
    }

    public function setBrowsePageSize(int $size): void
    {
        $allowed = [5, 10, 15, 20, 50, 100];
        if (in_array($size, $allowed, true)) {
            $this->browsePageSize = $size;
            $this->setBrowsePage(1);
        }
    }

    public function setBrowsePage(int $page): void
    {
        $this->browseCurrentPage = max(1, $page);
        $this->gotoPage($this->browseCurrentPage);
    }

    /** @return array<string, string> */
        };
    }

    public static function browseFilterBar(): Closure
    {
        return function (): string {
        $sortLabels = ['relevance' => 'Relevance', 'downloads' => 'Downloads', 'follows' => 'Follows', 'newest' => 'Newest', 'updated' => 'Last updated'];
        $curSortLabel = $sortLabels[$this->browseSortMode] ?? 'Relevance';
        $chevSvg  = "<svg width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>";
        $checkSvg = "<svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='#1bd96a' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'/></svg>";
        $panelCheckSvg = "<svg class='pmm-browse-facet-check' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.7' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'/></svg>";
        $slashSvg = "<svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='9'/><path d='M5.7 5.7l12.6 12.6'/></svg>";
        $filterSvg = "<svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><path d='M3 5h18'/><path d='M6 12h12'/><path d='M10 19h4'/></svg>";
        $makeIcon = fn (string $body) => "<svg fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' viewBox='0 0 24 24'>{$body}</svg>";
        $categoryIcons = [
            'adventure' => $makeIcon("<circle cx='12' cy='12' r='10'/><path d='m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36z'/>"),
            'cursed' => $makeIcon("<rect x='7' y='7.5' rx='5'/><path d='m2 12.5 2 2h3M22 12.5l-2 2h-3M3 21.5l2-3 2-1M21 21.5l-2-3-2-1M3 8.5l2 2 2 1M21 8.5l-2 2-2 1M12 7.5v14M15.38 8.82A3 3 0 0 0 16 7h0a3 3 0 0 0-3-3h-2a3 3 0 0 0-3 3h0a3 3 0 0 0 .61 1.82M9 4.5l-1-2M15 4.5l1-2'/>"),
            'decoration' => $makeIcon("<path d='m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'/><path d='M9 22V12h6v10'/>"),
            'economy' => $makeIcon("<path d='M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6'/>"),
            'equipment' => $makeIcon("<path d='M17.573 20.038 3.849 7.913 2.753 2.755 7.838 4.06 19.47 18.206l-1.898 1.832zM7.45 14.455l-3.043 3.661 1.887 1.843 3.717-3.25M16.75 10.82l3.333-2.913 1.123-5.152-5.091 1.28-2.483 2.985'/><path d='m21.131 16.602-5.187 5.01 2.596-2.508 2.667 2.761M2.828 16.602l5.188 5.01-2.597-2.508-2.667 2.761'/>"),
            'food' => $makeIcon("<path d='M2.27 21.7s9.87-3.5 12.73-6.36a4.5 4.5 0 0 0-6.36-6.37C5.77 11.84 2.27 21.7 2.27 21.7M8.64 14l-2.05-2.04M15.34 15l-2.46-2.46'/><path d='M22 9s-1.33-2-3.5-2C16.86 7 15 9 15 9s1.33 2 3.5 2S22 9 22 9'/><path d='M15 2s-2 1.33-2 3.5S15 9 15 9s2-1.84 2-3.5C17 3.33 15 2 15 2'/>"),
            'game-mechanics' => $makeIcon("<path d='M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6'/>"),
            'library' => $makeIcon("<path d='M4 19.5A2.5 2.5 0 0 1 6.5 17H20'/><path d='M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2'/>"),
            'magic' => $makeIcon("<path d='M15 4V2M15 16v-2M8 9h2M20 9h2M17.8 11.8 19 13M17.8 6.2 19 5M3 21l9-9M12.2 6.2 11 5'/>"),
            'management' => $makeIcon("<rect width='20' height='8' x='2' y='2' rx='2' ry='2'/><rect width='20' height='8' x='2' y='14' rx='2' ry='2'/><path d='M6 6h.01M6 18h.01'/>"),
            'minigame' => $makeIcon("<circle cx='12' cy='8' r='7'/><path d='M8.21 13.89 7 23l5-3 5 3-1.21-9.12'/>"),
            'mobs' => "<svg fill-rule='evenodd' stroke-linejoin='round' stroke-miterlimit='1.5' clip-rule='evenodd' viewBox='0 0 24 24'><path fill='none' d='M0 0h24v24H0z'/><path fill='none' stroke='currentColor' stroke-width='2' d='M3 3h18v18H3z'/><path fill='currentColor' stroke='currentColor' d='M6 6h4v4H6zm8 0h4v4h-4zm-4 4h4v2h2v6h-2v-2h-4v2H8v-6h2z'/></svg>",
            'optimization' => $makeIcon("<path d='M13 2 3 14h9l-1 8 10-12h-9z'/>"),
            'social' => $makeIcon("<path d='M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z'/>"),
            'storage' => $makeIcon("<path d='M21 8v13H3V8M1 3h22v5H1zM10 12h4'/>"),
            'technology' => $makeIcon("<path d='M22 12H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11M6 16h.01M10 16h.01'/>"),
            'transportation' => $makeIcon("<path d='M1 3h15v13H1zM16 8h4l3 3v5h-7z'/><circle cx='5.5' cy='18.5' r='2.5'/><circle cx='18.5' cy='18.5' r='2.5'/>"),
            'utility' => $makeIcon("<rect width='20' height='14' x='2' y='7' rx='2' ry='2'/><path d='M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16'/>"),
            'worldgen' => "<svg fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' d='M3.055 11H5a2 2 0 0 1 2 2v1a2 2 0 0 0 2 2 2 2 0 0 1 2 2v2.945M8 3.935V5.5A2.5 2.5 0 0 0 10.5 8h.5a2 2 0 0 1 2 2 2 2 0 1 0 4 0 2 2 0 0 1 2-2h1.064M15 20.488V18a2 2 0 0 1 2-2h3.064M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'/></svg>",
        ];
        $envIcons = [
            'client' => "<svg fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9.75 17 9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2'/></svg>",
            'server' => $makeIcon("<path d='M22 12H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11M6 16h.01M10 16h.01'/>"),
            'open-source' => "<span style='width:14px;display:inline-block'></span>",
        ];
        $openSourceIcon = $envIcons['open-source'];

        // Shared dropdown button style
        $ddBtn = "display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;border:1px solid rgba(255,255,255,0.12);color:#a1a1aa;background:rgba(255,255,255,0.04);transition:all 0.15s ease;";
        $ddBtnHov = "onmouseover=\"this.style.color='#ffffff';this.style.borderColor='rgba(255,255,255,0.25)'\" onmouseout=\"this.style.color='#a1a1aa';this.style.borderColor='rgba(255,255,255,0.12)'\"";

        // ── Search bar ──
        $searchSvg = "<svg style='position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#6b7280;flex-shrink:0;pointer-events:none;' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='11' cy='11' r='8'/><line x1='21' y1='21' x2='16.65' y2='16.65'/></svg>";
        $categoryItems = '';
        foreach ($this->getBrowseCategoryOptions() as $slug => $label) {
            $icon = $categoryIcons[$slug] ?? $makeIcon("<circle cx='12' cy='12' r='9'/>");
            $categoryItems .= "<div class='pmm-browse-facet-row' :class=\"excCats.includes('{$slug}') ? 'pmm-browse-facet-excluded' : ''\">"
                . "<button type='button' class='pmm-browse-facet-choice' "
                . "x-on:click=\"selectFacet(cats, excCats, '{$slug}'); \$wire.call('toggleBrowseCategoryFilter', '{$slug}')\" :class=\"cats.includes('{$slug}') ? 'pmm-browse-facet-active' : ''\">"
                . "<span class='pmm-browse-facet-left'>{$icon}<span>" . e($label) . "</span></span>"
                . "<span x-show=\"cats.includes('{$slug}')\" x-cloak>{$panelCheckSvg}</span>"
                . "<span x-show=\"!cats.includes('{$slug}') && !excCats.includes('{$slug}')\" x-cloak class='pmm-browse-facet-check-hover'>{$panelCheckSvg}</span>"
                . "</button>"
                . "<button type='button' class='pmm-browse-facet-exclude' "
                . "x-on:click.stop=\"excludeFacet(excCats, cats, '{$slug}'); \$wire.call('toggleBrowseExcludedCategoryFilter', '{$slug}')\">{$slashSvg}<span>Exclude</span></button>"
                . "</div>";
        }

        $environmentItems = '';
        foreach (['client' => 'Client', 'server' => 'Server'] as $env => $label) {
            $icon = $envIcons[$env];
            $environmentItems .= "<div class='pmm-browse-facet-row' :class=\"excEnvs.includes('{$env}') ? 'pmm-browse-facet-excluded' : ''\">"
                . "<button type='button' class='pmm-browse-facet-choice' "
                . "x-on:click=\"selectFacet(envs, excEnvs, '{$env}'); \$wire.call('toggleBrowseEnvironmentFilter', '{$env}')\" :class=\"envs.includes('{$env}') ? 'pmm-browse-facet-active' : ''\">"
                . "<span class='pmm-browse-facet-left'>{$icon}<span>" . e($label) . "</span></span>"
                . "<span x-show=\"envs.includes('{$env}')\" x-cloak>{$panelCheckSvg}</span>"
                . "<span x-show=\"!envs.includes('{$env}') && !excEnvs.includes('{$env}')\" x-cloak class='pmm-browse-facet-check-hover'>{$panelCheckSvg}</span>"
                . "</button>"
                . "<button type='button' class='pmm-browse-facet-exclude' "
                . "x-on:click.stop=\"excludeFacet(excEnvs, envs, '{$env}'); \$wire.call('toggleBrowseExcludedEnvironmentFilter', '{$env}')\">{$slashSvg}<span>Exclude</span></button>"
                . "</div>";
        }

        $catsJson = e(json_encode(array_values($this->browseCategoryFilters)));
        $excCatsJson = e(json_encode(array_values($this->browseExcludedCategoryFilters)));
        $envJson = e(json_encode(array_values($this->browseEnvironmentFilters)));
        $excEnvJson = e(json_encode(array_values($this->browseExcludedEnvironmentFilters)));
        $openSourceJson = $this->browseOpenSourceOnly ? 'true' : 'false';
        $excludeOpenSourceJson = $this->browseExcludeOpenSource ? 'true' : 'false';
        $hideInstalledJson = $this->browseHideInstalled ? 'true' : 'false';
        $filterDropdown = "<div wire:ignore x-data=\"{ open:false, px:0, py:0, cats:{$catsJson}, excCats:{$excCatsJson}, envs:{$envJson}, excEnvs:{$excEnvJson}, openSource:{$openSourceJson}, excludeOpenSource:{$excludeOpenSourceJson}, hideInstalled:{$hideInstalledJson}, catOpen:true, envOpen:true, licenseOpen:true, remove(list, value){ const i=list.indexOf(value); if(i !== -1) list.splice(i, 1); }, selectFacet(list, other, value){ const i=list.indexOf(value); this.remove(other, value); i === -1 ? list.push(value) : list.splice(i, 1); }, excludeFacet(list, other, value){ const i=list.indexOf(value); this.remove(other, value); i === -1 ? list.push(value) : list.splice(i, 1); }, activeCount(){ return this.cats.length + this.excCats.length + this.envs.length + this.excEnvs.length + (this.openSource ? 1 : 0) + (this.excludeOpenSource ? 1 : 0) + (this.hideInstalled ? 1 : 0); } }\" style='display:inline-flex;'>"
            . "<button type='button' x-ref='bfilterbtn' x-on:click.stop=\"if(!open){let r=\$refs.bfilterbtn.getBoundingClientRect();py=r.bottom+6;px=Math.max(8, Math.min(window.innerWidth-300, r.right-292));} open=!open\" "
            . "style=\"{$ddBtn}height:40px;padding:0 12px;\" {$ddBtnHov}>{$filterSvg}<span>Filters</span>"
            . "<span x-show='activeCount() > 0' x-cloak x-text='activeCount()' style='display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#1bd96a;color:#102016;font-size:11px;font-weight:800;'></span>"
            . "</button>"
            . "<template x-teleport='body'><div x-show='open' x-cloak x-on:click.away='open=false' class='pmm-browse-filter-panel' "
            . ":style=\"'position:fixed;top:'+py+'px;left:'+px+'px;max-height:calc(100vh - '+(py+8)+'px);z-index:9999;'\">"
            . "<button type='button' class='pmm-browse-filter-toggle' x-on:click=\"hideInstalled=!hideInstalled; \$wire.call('toggleBrowseHideInstalled')\">"
            . "<span>Hide already installed content</span><span class='pmm-browse-mini-switch' :class=\"hideInstalled ? 'pmm-browse-mini-switch-on' : ''\"></span></button>"
            . "<div class='pmm-browse-filter-section'><button type='button' class='pmm-browse-filter-title' x-on:click='catOpen=!catOpen' style='width:100%;padding:0;background:transparent;border:0;cursor:pointer;text-align:left;'>"
            . "<span>Category</span><span x-show='catOpen'>{$chevSvg}</span><span x-show='!catOpen' x-cloak style='transform:rotate(180deg)'>{$chevSvg}</span></button>"
            . "<div x-show='catOpen' x-cloak>{$categoryItems}</div></div>"
            . "<div class='pmm-browse-filter-section'><button type='button' class='pmm-browse-filter-title' x-on:click='envOpen=!envOpen' style='width:100%;padding:0;background:transparent;border:0;cursor:pointer;text-align:left;'>"
            . "<span>Environment</span><span x-show='envOpen'>{$chevSvg}</span><span x-show='!envOpen' x-cloak style='transform:rotate(180deg)'>{$chevSvg}</span></button>"
            . "<div x-show='envOpen' x-cloak>{$environmentItems}</div></div>"
            . "<div class='pmm-browse-filter-section'><button type='button' class='pmm-browse-filter-title' x-on:click='licenseOpen=!licenseOpen' style='width:100%;padding:0;background:transparent;border:0;cursor:pointer;text-align:left;'>"
            . "<span>License</span><span x-show='licenseOpen'>{$chevSvg}</span><span x-show='!licenseOpen' x-cloak style='transform:rotate(180deg)'>{$chevSvg}</span></button>"
            . "<div x-show='licenseOpen' x-cloak><div class='pmm-browse-facet-row' :class=\"excludeOpenSource ? 'pmm-browse-facet-excluded' : ''\">"
            . "<button type='button' class='pmm-browse-facet-choice' "
            . "x-on:click=\"openSource=!openSource; if(openSource) excludeOpenSource=false; \$wire.call('toggleBrowseOpenSourceFilter')\" :class=\"openSource ? 'pmm-browse-facet-active' : ''\">"
            . "<span class='pmm-browse-facet-left'>{$openSourceIcon}<span>Open source</span></span>"
            . "<span x-show='openSource' x-cloak>{$panelCheckSvg}</span>"
            . "<span x-show='!openSource && !excludeOpenSource' x-cloak class='pmm-browse-facet-check-hover'>{$panelCheckSvg}</span></button>"
            . "<button type='button' class='pmm-browse-facet-exclude' "
            . "x-on:click.stop=\"excludeOpenSource=!excludeOpenSource; if(excludeOpenSource) openSource=false; \$wire.call('toggleBrowseExcludeOpenSourceFilter')\">{$slashSvg}<span>Exclude</span></button>"
            . "</div></div></div>"
            . "</div></template></div>";

        $searchBar = "<div style='display:flex;align-items:center;gap:8px;margin-bottom:10px;'>"
            . "<div style='position:relative;flex:1;min-width:0;'>"
            . $searchSvg
            . "<input type='text' wire:model.live.debounce.400ms='browseSearch' placeholder='Search mods...' "
            . "style='width:100%;height:40px;padding:10px 16px 10px 40px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#f3f4f6;font-size:14px;outline:none;box-sizing:border-box;transition:border-color 0.15s ease;' "
            . "onfocus=\"this.style.borderColor='rgba(27,217,106,0.4)'\" onblur=\"this.style.borderColor='rgba(255,255,255,0.1)'\"/>"
            . "</div>{$filterDropdown}</div>";

        // ── Sort dropdown (x-teleport) ──
        $sortOpts = '';
        foreach ($sortLabels as $k => $lbl) {
            $active = $this->browseSortMode === $k;
            $icon   = $active ? $checkSvg : "<span style='width:12px;display:inline-block'></span>";
            $color  = $active ? '#1bd96a' : '#e4e4e7';
            $sortOpts .= "<button type='button' wire:click=\"setBrowseSort('{$k}')\" x-on:click=\"open=false\" "
                . "style='display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:6px;font-size:13px;font-weight:500;color:{$color};background:transparent;border:none;cursor:pointer;white-space:nowrap;text-align:left;' "
                . "onmouseover=\"this.style.background='rgba(255,255,255,0.07)'\" onmouseout=\"this.style.background='transparent'\">"
                . $icon . e($lbl) . "</button>";
        }
        $sortDropdown = "<div x-data=\"{ open:false, py:0, px:0 }\" style='display:inline-flex;'>"
            . "<button type='button' x-ref='bsortbtn' x-on:click.stop=\"if(!open){let r=\$refs.bsortbtn.getBoundingClientRect();py=r.bottom+6;px=r.left;} open=!open\" style=\"{$ddBtn}\" {$ddBtnHov}>"
            . "<span style='color:#6b7280;font-weight:400;margin-right:2px;'>Sort by:</span> " . e($curSortLabel) . " " . $chevSvg
            . "</button>"
            . "<template x-teleport='body'><div x-show='open' x-cloak x-on:click.away='open=false' "
            . ":style=\"'position:fixed;top:'+py+'px;left:'+px+'px;background:#18181b;border:1px solid #3f3f46;border-radius:10px;padding:4px;min-width:175px;z-index:9999;box-shadow:0 12px 32px rgba(0,0,0,0.6);'\">"
            . $sortOpts . "</div></template></div>";

        // ── View: X dropdown ──
        $viewOpts = '';
        foreach ([5, 10, 15, 20, 50, 100] as $sz) {
            $active = $this->browsePageSize === $sz;
            $icon   = $active ? $checkSvg : "<span style='width:12px;display:inline-block'></span>";
            $color  = $active ? '#1bd96a' : '#e4e4e7';
            $viewOpts .= "<button type='button' wire:click=\"setBrowsePageSize({$sz})\" x-on:click=\"open=false\" "
                . "style='display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:6px;font-size:13px;font-weight:500;color:{$color};background:transparent;border:none;cursor:pointer;text-align:left;' "
                . "onmouseover=\"this.style.background='rgba(255,255,255,0.07)'\" onmouseout=\"this.style.background='transparent'\">"
                . $icon . $sz . "</button>";
        }
        $viewDropdown = "<div x-data=\"{ open:false, py:0, px:0 }\" style='display:inline-flex;'>"
            . "<button type='button' x-ref='bviewbtn' x-on:click.stop=\"if(!open){let r=\$refs.bviewbtn.getBoundingClientRect();py=r.bottom+6;px=r.left;} open=!open\" style=\"{$ddBtn}\" {$ddBtnHov}>"
            . "<span style='color:#6b7280;font-weight:400;margin-right:2px;'>View:</span> {$this->browsePageSize} " . $chevSvg
            . "</button>"
            . "<template x-teleport='body'><div x-show='open' x-cloak x-on:click.away='open=false' "
            . ":style=\"'position:fixed;top:'+py+'px;left:'+px+'px;background:#18181b;border:1px solid #3f3f46;border-radius:10px;padding:4px;min-width:100px;z-index:9999;box-shadow:0 12px 32px rgba(0,0,0,0.6);'\">"
            . $viewOpts . "</div></template></div>";

        // ── Top pagination (smaller) ──
        $topPagination = '';
        if ($this->browseTotalPages > 1) {
            $cur   = $this->browseCurrentPage;
            $total = $this->browseTotalPages;

            $pBtnBase = "display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 6px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;border:1px solid;transition:all 0.15s ease;background:none;";
            $pBtnOff  = $pBtnBase . "border-color:rgba(255,255,255,0.1);color:#a1a1aa;";
            $pBtnOn   = $pBtnBase . "border-color:rgba(27,217,106,0.5);color:#1bd96a;background:rgba(27,217,106,0.1);";
            $pBtnHov  = " onmouseover=\"if(this.dataset.cur!='1'){this.style.background='rgba(255,255,255,0.07)';this.style.borderColor='rgba(255,255,255,0.25)';this.style.color='#ffffff'}\" onmouseout=\"if(this.dataset.cur!='1'){this.style.background='none';this.style.borderColor='rgba(255,255,255,0.1)';this.style.color='#a1a1aa'}\"";

            $renderPage = function (int $n) use ($cur, $pBtnOn, $pBtnOff, $pBtnHov): string {
                $active = $n === $cur;
                $style  = $active ? $pBtnOn : $pBtnOff;
                $data   = $active ? " data-cur='1'" : '';
                return "<button type='button' wire:click=\"setBrowsePage({$n})\"{$data} style=\"{$style}\"{$pBtnHov}>{$n}</button>";
            };

            $pages = [];
            $window = 2; // pages each side of current
            for ($i = 1; $i <= $total; $i++) {
                if ($i === 1 || $i === $total || ($i >= $cur - $window && $i <= $cur + $window)) {
                    $pages[] = $i;
                }
            }

            $ellipsis = "<span style='display:inline-flex;align-items:center;color:#4b5563;font-size:12px;padding:0 4px;'>…</span>";
            $prevPage = $cur > 1
                ? "<button type='button' wire:click=\"setBrowsePage(" . ($cur - 1) . ")\" style=\"{$pBtnOff}\" {$pBtnHov}>&lsaquo;</button>"
                : "<button disabled style=\"{$pBtnOff}opacity:0.3;cursor:default\">&lsaquo;</button>";
            $nextPage = $cur < $total
                ? "<button type='button' wire:click=\"setBrowsePage(" . ($cur + 1) . ")\" style=\"{$pBtnOff}\" {$pBtnHov}>&rsaquo;</button>"
                : "<button disabled style=\"{$pBtnOff}opacity:0.3;cursor:default\">&rsaquo;</button>";

            $paginationHtml = $prevPage;
            $prev = null;
            foreach ($pages as $p) {
                if ($prev !== null && $p - $prev > 1) {
                    $paginationHtml .= $ellipsis;
                }
                $paginationHtml .= $renderPage($p);
                $prev = $p;
            }
            $paginationHtml .= $nextPage;

            $topPagination = "<div style='display:flex;align-items:center;gap:3px;'>{$paginationHtml}</div>";
        }

        $row2 = "<div style='display:flex;align-items:center;justify-content:space-between;'>"
            . "<div style='display:flex;align-items:center;gap:8px;'>{$sortDropdown}{$viewDropdown}</div>"
            . "<div>{$topPagination}</div>"
            . "</div>";

        return "<div class='pmm-browse-bar' style='padding:0 0 8px 0;'>{$searchBar}{$row2}</div>";
        };
    }

}
