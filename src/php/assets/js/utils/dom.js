/**
 * Lightweight DOM helpers
 */
export const $ = (sel, ctx = document) => ctx.querySelector(sel);
export const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

export function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'className') node.className = v;
    else if (k === 'textContent') node.textContent = v;
    else if (k === 'innerHTML') node.innerHTML = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v);
  }
  for (const c of children) {
    if (typeof c === 'string') node.appendChild(document.createTextNode(c));
    else node.appendChild(c);
  }
  return node;
}

export function clear(el) { el.innerHTML = ''; }

export function show(el) { el.style.display = ''; }
export function hide(el) { el.style.display = 'none'; }

export function flashPrice(el, isUp) {
  el.classList.remove('price-flash-up', 'price-flash-down');
  void el.offsetWidth; // reflow
  el.classList.add(isUp ? 'price-flash-up' : 'price-flash-down');
}
