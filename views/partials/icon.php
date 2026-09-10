<?php

declare(strict_types=1);

/**
 * Lokale Inline-SVG-Icons (keine externen Ressourcen).
 *
 * @var string $icon
 */
$icons = [
    'document' => '<path d="M14 3v5h5"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M9 13h6M9 17h6"/>',
    'app' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'phone' => '<path d="M6 3h4l2 5-2.5 1.5a12 12 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z"/>',
    'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4"/><circle cx="12" cy="17" r="0.6" fill="currentColor"/>',
    'tools' => '<path d="M14 6a4 4 0 1 0 4 4l3 3-4 4-3-3a4 4 0 0 0-4-4z"/><path d="M7 21 3 17l6-6"/>',
    'robot' => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4"/><circle cx="9" cy="14" r="1" fill="currentColor"/><circle cx="15" cy="14" r="1" fill="currentColor"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/>',
    'helmet' => '<circle cx="12" cy="9" r="2.5"/><path d="M7 9a5 5 0 0 1 10 0"/><path d="M6 9h12"/><path d="M8 21v-2a4 4 0 0 1 8 0v2"/>',
    'wrench' => '<path d="M21 3a4 4 0 0 1-4.24 5.65L7 18.4a2 2 0 1 1-2.4-2.4l9.75-9.76A4 4 0 0 1 21 3z"/>',
    'snail' => '<circle cx="15" cy="10" r="4"/><circle cx="15" cy="10" r="1.4" fill="currentColor"/><path d="M4 17c0-2 1.5-3 3.5-3H15"/><path d="M4 17a3 3 0 0 0 3 3h9a4 4 0 0 0 4-4"/><path d="M6 8V6"/><path d="M8 8V6"/>',
    'beacon' => '<path d="M9 10a3 3 0 1 1 6 0c0 2-1 3-1 5h-4c0-2-1-3-1-5z"/><path d="M9 15h6v2H9z"/><path d="M11 17h2v4h-2z"/><path d="M5 9l1.5 1"/><path d="M19 9l-1.5 1"/><path d="M12 4v2"/>',
    'ekg' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M6 12h3l2-4 2 8 2-4h3"/>',
    'warning' => '<rect x="6.34" y="6.34" width="11.31" height="11.31" rx="2" transform="rotate(45 12 12)"/><path d="M12 8.5v5"/><circle cx="12" cy="16" r="0.6" fill="currentColor"/>',
];

$key = isset($icon) && is_string($icon) && isset($icons[$icon]) ? $icon : 'link';
?>
<svg class="tile__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <?= $icons[$key] ?>
</svg>
