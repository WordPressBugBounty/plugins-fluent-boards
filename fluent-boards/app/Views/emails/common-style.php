<?php if (!defined('ABSPATH')) exit; // if accessed directly exit ?>
<?php
/*
 * Shared email styles.
 *
 * Light colors live inline on every element so clients that drop <style> still render the
 * light design. This block only adds what inline styles cannot: resets, the narrow-screen
 * layout, URL wrapping (stripped from inline styles by wp_kses_post) and the dark scheme.
 *
 * Dark scheme: Apple Mail / iOS / Outlook for Mac read prefers-color-scheme, Outlook.com
 * marks its dark mode with [data-ogsc]. Gmail ignores both and inverts on its own, which is
 * why the light palette avoids pure black on white.
 */
?>
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin: 0; padding: 0; width: 100% !important; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table { border-collapse: collapse; }
    img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
    p { margin: 0; }
    a { color: #335CFF; }
    .fbs_email_comment_body p { margin: 0 0 8px; }
    .fbs_email_comment_body p:last-child { margin-bottom: 0; }
    .fbs_email_comment_body img { max-width: 100%; height: auto; }
    .fbs_email_url { word-break: break-all; overflow-wrap: anywhere; }

    @media only screen and (max-width: 480px) {
        .fbs_email_outer { padding: 24px 12px !important; }
        .fbs_email_card_pad { padding: 20px !important; }
        .fbs_email_heading { font-size: 20px !important; line-height: 28px !important; }
        .fbs_email_kv_label { width: 80px !important; }
    }

    @media (prefers-color-scheme: dark) {
        .fbs_email_body, .fbs_email_outer { background: #151D26 !important; }
        .fbs_email_card { background: #1C2732 !important; border-color: #2C3C4E !important; }
        .fbs_email_text { color: #F5F7FA !important; }
        .fbs_email_text2 { color: #99A0AE !important; }
        .fbs_email_link { color: #7C97FF !important; }
        .fbs_email_border { border-color: #2C3C4E !important; }
        .fbs_email_inset { background: #222F3D !important; border-color: #2C3C4E !important; }
        .fbs_email_btn { background: #F5F7FA !important; color: #0E121B !important; }
        .fbs_email_btn_secondary { color: #F5F7FA !important; border-color: #2C3C4E !important; }
        .fbs_email_logo_tile { background: #F5F7FA !important; color: #0E121B !important; }
        .fbs_email_pill_accent { background: #1C254D !important; color: #A9BBFF !important; }
        .fbs_email_pill_neutral { background: #26323F !important; color: #CACFD8 !important; }
        .fbs_email_pill_warn { background: #3B2E12 !important; color: #FFD268 !important; }
        .fbs_email_pill_ok { background: #163828 !important; color: #3EE089 !important; }
        .fbs_email_warn_text { color: #FFD268 !important; }
    }

    [data-ogsc] .fbs_email_body, [data-ogsc] .fbs_email_outer { background: #151D26 !important; }
    [data-ogsc] .fbs_email_card { background: #1C2732 !important; border-color: #2C3C4E !important; }
    [data-ogsc] .fbs_email_text { color: #F5F7FA !important; }
    [data-ogsc] .fbs_email_text2 { color: #99A0AE !important; }
    [data-ogsc] .fbs_email_link { color: #7C97FF !important; }
    [data-ogsc] .fbs_email_border { border-color: #2C3C4E !important; }
    [data-ogsc] .fbs_email_inset { background: #222F3D !important; border-color: #2C3C4E !important; }
    [data-ogsc] .fbs_email_btn { background: #F5F7FA !important; color: #0E121B !important; }
    [data-ogsc] .fbs_email_btn_secondary { color: #F5F7FA !important; border-color: #2C3C4E !important; }
    [data-ogsc] .fbs_email_logo_tile { background: #F5F7FA !important; color: #0E121B !important; }
    [data-ogsc] .fbs_email_pill_accent { background: #1C254D !important; color: #A9BBFF !important; }
    [data-ogsc] .fbs_email_pill_neutral { background: #26323F !important; color: #CACFD8 !important; }
    [data-ogsc] .fbs_email_pill_warn { background: #3B2E12 !important; color: #FFD268 !important; }
    [data-ogsc] .fbs_email_pill_ok { background: #163828 !important; color: #3EE089 !important; }
    [data-ogsc] .fbs_email_warn_text { color: #FFD268 !important; }
</style>
