</div> <!-- /.container -->

<!-- Thin divider -->
<hr class="footer-rule">

<footer class="site-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-4">
        <h6 class="footer-title">About</h6>
        <p class="footer-text">
          <strong><?= APP_NAME ?></strong> we're more than just a repair shop—we're your trusted partner in keeping your devices running seamlessly in today's fast-paced, hybrid world. 🌙/☀️.
        </p>
      </div>

      <div class="col-md-4">
        <h6 class="footer-title">Quick links</h6>
        <ul class="footer-links">
          <li><a href="<?= BASE_URL ?>/?page=dashboard">Dashboard</a></li>
          <li><a href="<?= BASE_URL ?>/?page=products">Products</a></li>
          <li><a href="<?= BASE_URL ?>/?page=sales">Sales</a></li>
          <li><a href="<?= BASE_URL ?>/?page=credits">Credit</a></li>
          <li><a href="<?= BASE_URL ?>/?page=expenses">Expenses</a></li>
          <li><a href="<?= BASE_URL ?>/?page=reports">Reports</a></li>
        </ul>
      </div>

      <div class="col-md-4">
        <h6 class="footer-title">Need help?</h6>
        <ul class="footer-list">
          <li>💡 Tip: <code>Ctrl/Cmd + D</code> toggles dark mode.</li>
          <li>🔍 Center search finds products fast.</li>
          <li>📦 Low stock appears on the dashboard.</li>
        </ul>
      </div>
    </div>

    <!-- Bottom bar: LEFT (time) — CENTER (copyright) — RIGHT (back to top) -->
    <div class="footer-inline">
      <div class="fi-left">
        <strong>Local time: <span id="nowClock">--:--:--</span></strong>
      </div>

      <div class="fi-center">Copyright reserved
        © <?= date('Y') ?> <?= APP_NAME ?>.
      </div>

      <div class="fi-right">
        <button class="btn btn-sm btn-primary circle-btn" id="scrollTopBtn" type="button" title="Back to top">↑</button>
      </div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Shared product search
async function wireProductSearch(inputId){
  const box = document.getElementById(inputId);
  const list = document.getElementById('product-list');
  if (!box || !list) return;
  let controller = null;
  box.addEventListener('input', async () => {
    const q = box.value.trim();
    if (q.length < 2) return;
    try {
      if (controller) controller.abort();
      controller = new AbortController();
      const res = await fetch('../api/search_products.php?q=' + encodeURIComponent(q), { signal: controller.signal });
      if (!res.ok) return;
      const data = await res.json();
      list.innerHTML = '';
      data.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.name + ' (' + p.sku + ')';
        list.appendChild(opt);
      });
    } catch(e) {}
  });
}

// Theme toggle + keyboard shortcut
(function(){
  const key = 'pos-theme';
  const body = document.body;
  const btn = document.getElementById('themeToggle');
  const saved = localStorage.getItem(key);
  if (saved === 'dark') body.setAttribute('data-theme','dark');

  const applyIcon = () => { if (btn) btn.textContent = body.getAttribute('data-theme') === 'dark' ? '☀️' : '🌙'; };
  applyIcon();

  function toggleTheme(){
    const isDark = body.getAttribute('data-theme') === 'dark';
    if (isDark){ body.removeAttribute('data-theme'); localStorage.setItem(key,'light'); }
    else { body.setAttribute('data-theme','dark'); localStorage.setItem(key,'dark'); }
    applyIcon();
  }
  if (btn) btn.addEventListener('click', toggleTheme);
  document.addEventListener('keydown', (e) => {
    const isMac = navigator.platform.toUpperCase().includes('MAC');
    if ((isMac && e.metaKey && e.key.toLowerCase()==='d') || (!isMac && e.ctrlKey && e.key.toLowerCase()==='d')){
      e.preventDefault(); toggleTheme();
    }
  });

  // Wire searches
  wireProductSearch('searchBox');
  wireProductSearch('searchBoxMobile');

  // Live clock
  const clock = document.getElementById('nowClock');
  function tick(){
    const d = new Date();
    if (clock) clock.textContent = d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }
  tick(); setInterval(tick, 1000);

  // Back to top (in-footer, right corner)
  document.getElementById('scrollTopBtn').addEventListener('click', () =>
    window.scrollTo({top:0, behavior:'smooth'})
  );
})();
</script>
<script>
// --------- Global search wiring (desktop + mobile) ----------
(function(){
  const inputs = [document.getElementById('searchBox'), document.getElementById('searchBoxMobile')].filter(Boolean);

  inputs.forEach(input => {
    // Suggestion box
    const menu = document.createElement('div');
    menu.className = 'search-suggest shadow';
    input.parentElement.appendChild(menu);

    let idx = -1; // keyboard selection index
    let items = [];

    function render(data){
      items = [];
      menu.innerHTML = '';
      const groups = [
        ['products','Products'],
        ['customers','Customers'],
        ['sales','Sales'],
        ['credits','Credits']
      ];
      let count = 0;
      groups.forEach(([key, label])=>{
        const arr = data[key] || [];
        if (!arr.length) return;
        const h = document.createElement('div');
        h.className = 'search-head'; h.textContent = label;
        menu.appendChild(h);
        arr.forEach(o=>{
          count++;
          const a = document.createElement('a');
          a.href = o.url; a.className = 'search-item'; a.dataset.type = o.type;
          a.innerHTML = `<span class="badge me-2">${o.type[0].toUpperCase()}</span> ${o.label}`;
          a.addEventListener('mouseover', ()=> highlight(a));
          a.addEventListener('mousedown', (e)=>{ /* open on click without blurring */ e.preventDefault(); window.location = o.url; });
          menu.appendChild(a);
          items.push(a);
        });
      });
      menu.style.display = count ? 'block' : 'none';
      idx = -1;
    }

    function highlight(el){
      items.forEach(x=>x.classList.remove('active'));
      el.classList.add('active');
      idx = items.indexOf(el);
    }

    let t;
    input.addEventListener('input', ()=>{
      const q = input.value.trim();
      clearTimeout(t);
      if (q.length < 2) { menu.style.display='none'; return; }
      t = setTimeout(async ()=>{
        try{
          const res = await fetch('<?= BASE_URL ?>/search.php?q=' + encodeURIComponent(q), {credentials:'same-origin'});
          const data = await res.json();
          render(data);
        }catch(e){ /* ignore */ }
      }, 160);
    });

    input.addEventListener('keydown', (e)=>{
      if (!['ArrowDown','ArrowUp','Enter','Escape'].includes(e.key)) return;
      if (e.key==='Escape'){ menu.style.display='none'; return; }
      if (e.key==='ArrowDown'){ e.preventDefault(); if (items.length){ idx = (idx+1) % items.length; highlight(items[idx]); } }
      if (e.key==='ArrowUp'){ e.preventDefault(); if (items.length){ idx = (idx-1+items.length) % items.length; highlight(items[idx]); } }
      if (e.key==='Enter'){
        if (idx>=0 && items[idx]) { window.location = items[idx].href; }
        else if (input.value.trim()!==''){ window.location = '<?= BASE_URL ?>/?page=search&q=' + encodeURIComponent(input.value.trim()); }
      }
    });

    input.addEventListener('blur', ()=> setTimeout(()=> menu.style.display='none', 150));
    input.addEventListener('focus', ()=> { if (menu.innerHTML) menu.style.display='block'; });
  });
})();
</script>

</body>
</html>
