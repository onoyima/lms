/* Meeting screen in the institution's colours (BigBlueButton HTML5 client). Only colours are overridden, so upgrades stay safe. */
:root {
  --color-primary: {{ $brand }};
  --btn-primary-bg: {{ $brand }};
  --btn-primary-border: {{ $brand }};
  --btn-primary-color: {{ $fg }};
  --color-text-primary: {{ $brand }};
  --color-link: {{ $brand }};
}
button[class*="primary"], [class*="btnPrimary"], [data-test="joinAudio"], [data-test="startRecording"] {
  background-color: {{ $brand }} !important;
  border-color: {{ $brand }} !important;
  color: {{ $fg }} !important;
}
[class*="branding"] { border-bottom: 3px solid {{ $brand }}; }
a, [class*="link"] { color: {{ $brand }}; }
