// Photo protection. Imported once by BaseLayout; the styles are in base.css (.wm).
//  1. The browser's "save image" routes are closed on photos: context menu and drag here, the long-press callout
//     and the drag ghost in CSS.
//  2. Every content photo carries the logo, so a screenshot or a photo of the screen shows whose it is.
// It works on whatever is in the page, so photos added later (new pages, references written in WordPress) are covered
// without anyone having to remember it.
//
// The logo comes from one of two places, never both. Photos served through the plugin's resize endpoint have it in
// the file itself (wp-plugin/includes/img-stamp.php), so a saved copy carries it too; everything else (repo assets,
// originals, the lightbox) gets it drawn on top here. Under `astro dev` the pictures come from the live site, whose
// plugin may not stamp yet, so there the logo is always drawn: it shows the result, not the exact placement.
//
// Limits, so nobody expects more: a page cannot detect a screenshot, which is why the logo is there all the time, and
// the original uploads stay reachable at their own URLs. This stops casual copying, not a determined visitor.

/** Rendered width below which an image is an icon, flag or seal rather than a photo. */
const MIN_WIDTH = 140;
const NOT_A_PHOTO = /logo|flag|gravatar|avatar/i;
/** No logo here: the brand's own logo, photos that sit darkened behind text, and the catalogue (its pages carry it). */
const UNMARKED = '.brand, .footer-brand, .hero:not(.hero--split) .hero__media, .cta__photo, .cat, [data-no-wm]';

/** Stamped by the endpoint: a copy URL carries the stamp version (`s`, src/lib/resize.mjs). */
const STAMPED = /[?&]upx_img=[^\s"']*?&(?:amp;)?s=\d/;
const inFile = (img: HTMLImageElement) => import.meta.env.PROD && STAMPED.test(`${img.getAttribute('srcset') ?? ''} ${img.getAttribute('src') ?? ''}`);

const isMedia = (target: EventTarget | null) => target instanceof Element && !!target.closest('img, picture, video, canvas, .wm');
document.addEventListener('contextmenu', (e) => {
  if (isMedia(e.target)) e.preventDefault();
});
document.addEventListener('dragstart', (e) => {
  if (isMedia(e.target)) e.preventDefault();
});

/**
 * Photos that fill their box get the logo in a corner. A picture shown whole (object-fit: contain: renders, CAD
 * drawings, cut-outs) is smaller than its box and often has empty margins of its own, so a corner would put the logo
 * beside the object: there it goes in the middle, where it cannot be cropped away. `data-wm="center"` asks for the
 * same on a box whose picture is a cut-out.
 */
const mark = (box: HTMLElement, img?: HTMLImageElement) => {
  if (!box.classList.contains('wm')) {
    box.classList.add('wm');
    const logo = document.createElement('span');
    logo.className = 'wm__mark';
    logo.setAttribute('aria-hidden', 'true');
    box.append(logo);
  }
  box.classList.toggle('wm--center', box.dataset.wm === 'center' || (!!img && getComputedStyle(img).objectFit === 'contain'));
};

/** Hide the drawn logo on boxes whose picture already has it in the file (the picture can change: lightbox). */
const sync = (box: HTMLElement) => {
  const img = box.querySelector<HTMLImageElement>(':scope > img');
  box.classList.toggle('wm--in-file', !!img && inFile(img));
};

const scan = () => {
  // Boxes a component marks itself (the photo is set later, or it is a cut-out).
  document.querySelectorAll<HTMLElement>('[data-wm]').forEach((box) => mark(box));
  document.querySelectorAll<HTMLImageElement>('main img').forEach((img) => {
    const box = img.parentElement;
    if (!box || img.offsetWidth < MIN_WIDTH || NOT_A_PHOTO.test(img.currentSrc || img.src)) return;
    if (box.closest('.wm') || img.closest(UNMARKED)) return;
    // The logo is placed against the photo's parent, so the parent has to be the photo's own box.
    const ownBox = Math.abs(box.offsetWidth - img.offsetWidth) < 4 && Math.abs(box.offsetHeight - img.offsetHeight) < 4;
    if (ownBox) return mark(box, img);
    // Photos in running text (page bodies, WordPress content) may share a paragraph with text: give them a box.
    if (img.closest('.prose')) {
      const wrap = document.createElement('span');
      wrap.className = 'wm__box';
      img.replaceWith(wrap);
      wrap.append(img);
      mark(wrap, img);
    }
  });
  document.querySelectorAll<HTMLElement>('.wm').forEach(sync);
};

let timer = 0;
const rescan = () => {
  window.clearTimeout(timer);
  timer = window.setTimeout(scan, 200);
};
scan();
// Lazy photos get their size on load, and some arrive later (galleries, lists filled in by the WordPress plugin).
window.addEventListener('load', scan);
window.addEventListener('resize', rescan);
window.addEventListener('scroll', rescan, { passive: true });
new MutationObserver(rescan).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'srcset'] });
