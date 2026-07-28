const AJAX_URL = 'grid_ajax.php';

export async function api(endpoint, params = {}) {
  params._action = endpoint;
  const qs = new URLSearchParams(params).toString();
  const resp = await fetch(`${AJAX_URL}?${qs}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  if (!resp.ok) throw new Error(`API ${endpoint}: ${resp.status}`);
  return resp.json();
}

export async function apiPost(endpoint, data = {}) {
  data._action = endpoint;
  const resp = await fetch(AJAX_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams(data).toString(),
  });
  if (!resp.ok) throw new Error(`API POST ${endpoint}: ${resp.status}`);
  return resp.json();
}
