{{--
    Rotating SV logo hologram.

    The same projection the CAMI Command Center artifacts run (registry-map/cc.css
    + the *-template.html holo block), ported to this app. There it is a fixed,
    full-page backdrop on a permanently dark deck; here it is an inline stage that
    sits beside the stats, so it is contained to its own box and the projection is
    re-tinted per theme in app.css — a glow that reads on navy disappears on white.

    Decoration only: aria-hidden, no text, and the animations stop under
    prefers-reduced-motion.
--}}
<div class="holo" aria-hidden="true">
    <svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
        {{-- The projected glyph is the real Streamline Verify V — the same two
             filled strokes and two gradients as the header wordmark. Filled paths
             rather than a stroked check: the logo is a solid mark, and one stroke
             could not carry the white tip on the long arm.

             The glyph measures 60.28 x 72.21 in the logo's own space; the viewBox
             pads that to 78.28 x 108.21 (9 each side, 18 top and bottom) so the V
             fills ~67% of its box. .holo-l stretches this to the square stage, so
             preserving that fill fraction is what keeps the projection inside the
             mask's fade. Gradient ids are local to this svg. --}}
        <linearGradient id="holov1" x1="0" y1="0" x2="1" y2="0" gradientUnits="userSpaceOnUse" gradientTransform="matrix(-470.673,-470.673,470.673,-470.673,3508.92,508.781)"><stop offset="0" stop-color="#f14624"/><stop offset=".33401" stop-color="#f3822f"/><stop offset=".984689" stop-color="#ffffff"/><stop offset="1" stop-color="#ffffff"/></linearGradient>
        <linearGradient id="holov2" x1="0" y1="0" x2="1" y2="0" gradientUnits="userSpaceOnUse" gradientTransform="matrix(259.255,-259.255,259.255,259.255,2960.67,304.367)"><stop offset="0" stop-color="#f3822f"/><stop offset=".32303" stop-color="#f3822f"/><stop offset=".747584" stop-color="#ee5533"/><stop offset=".991515" stop-color="#ea2c35"/><stop offset="1" stop-color="#ea2c35"/></linearGradient>
        <symbol id="svcheck" viewBox="-9 -18 78.28 108.21">
            <g transform="matrix(.13333333,0,0,-.13333333,-397.6933,72.306667)">
                <path fill="url(#holov1)" d="M 3318.79,502.277 3181.07,197.195 c -0.86,-1.847 -1.51,-3.73 -2.09,-5.636 l -32.86,-75.438 c 25.51,-57.078 46.8,-77.8124 65.03,-72.0116 l 50.84,111.3676 c 0.83,1.855 1.5,3.742 2.09,5.644 l 123.38,277.18 47.32,96.359 c -11.34,4.379 -26.35,7.653 -42.14,7.653 -27.14,0.007 -56.6,-9.672 -73.85,-40.036"/>
                <path fill="url(#holov2)" d="m 2982.7,326.398 98.21,-278.9488 c 9.52,-27.414 35.05,-46.02342 64.06,-46.726544 28.93,-0.72656225 55.29,16.558644 66.19,43.386744 h -0.01 c -18.23,-5.8008 -34.47,6.2187 -59.96,63.3206 l -55.65,162.898 c -15.16,50.832 -38.62,65.883 -66,65.883 -14.71,0 -30.54,-4.344 -46.84,-9.813"/>
            </g>
        </symbol>
    </defs></svg>
    <div class="holo-stage">
        <div class="holo-spin"><svg class="holo-l"><use href="#svcheck"/></svg></div>
    </div>
</div>
