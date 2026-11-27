/**
 * @typedef {Object} IngredientGroup
 * @property {?string} group
 * @property {string[]} text
 */

/**
 * @typedef {Object} Recipe
 * @property {string} slug
 * @property {string} title
 * @property {?string} category
 * @property {?string} servings
 * @property {?string} image
 * @property {?string} thumb
 * @property {string} created
 * @property {?string} notes
 * @property {boolean} twoColumnPrint
 * @property {IngredientGroup[]} ingredients
 * @property {string[]} steps
 * @property {string} [import_warning]
 */

/**
 * Alle geladenen Rezepte (Quelle für Kartenraster, Kategorie-Filter und Detailansicht).
 * @type {Recipe[]}
 */
let RECIPES = [];

/**
 * `true`, während openEditForm()/openImportForm() offen ist (ungespeicherte Eingaben sind möglich).
 * Verhindert, dass ein Klick neben das Formular oder Escape die Eingaben versehentlich verwirft
 * (siehe overlay-Klick-Handler und keydown-Handler weiter unten) — nur die expliziten Buttons
 * "Abbrechen"/"×"/"Speichern" schließen das Formular dann noch.
 * @type {boolean}
 */
let formIsOpen = false;

/** Container des Rezept-Kartenrasters. @type {HTMLElement} */
const grid = document.getElementById('grid');
/** Abgedunkelter Hintergrund hinter dem Rezeptblatt/Formular; hat die Klasse `open`, wenn sichtbar. @type {HTMLElement} */
const overlay = document.getElementById('overlay');
/** Inhaltsbereich im Overlay (Lese-Ansicht, Bearbeitungs- oder Importformular). @type {HTMLElement} */
const dialog = document.getElementById('dialog');
/** Suchfeld. @type {HTMLInputElement} */
const searchInput = document.getElementById('search');
/** Auswahl der Sortierung. @type {HTMLSelectElement} */
const sortSel = document.getElementById('sortSel');
/** Auswahl des Kategorie-Filters. @type {HTMLSelectElement} */
const catSel = document.getElementById('catSel');
/** Anzeige "x von y Rezepten" über dem Kartenraster. @type {HTMLElement} */
const resultCount = document.getElementById('resultCount');
/** Anzeige der Gesamtanzahl im Header. @type {HTMLElement} */
const subCount = document.getElementById('subCount');
/** Button "Neues Rezept". @type {HTMLButtonElement} */
const newRecipeBtn = document.getElementById('newRecipeBtn');
/** Button "Importieren". @type {HTMLButtonElement} */
const importRecipeBtn = document.getElementById('importRecipeBtn');

/** Schlüssel in localStorage, unter dem das API-Token abgelegt wird. @type {string} */
const TOKEN_KEY = 'kochbuch_api_token';

/**
 * Sperre gegen das Abschalten des Bildschirms (Screen Wake Lock API), null ohne Sperre.
 * @type {?WakeLockSentinel}
 */
let wakeLock = null;

/**
 * Ob der Bildschirm anbleiben soll: nur, solange ein Rezept in der Lese-Ansicht offen ist (beim Kochen liegt das Handy
 * daneben), nicht in der Übersicht und nicht in den Formularen.
 * @type {boolean}
 */
let keepAwake = false;

/**
 * Hält den Bildschirm an bzw. gibt ihn wieder frei. Ohne die API (nur über HTTPS oder localhost, nicht jeder Browser)
 * bleibt der Bildschirm wie eingestellt.
 * @param {boolean} on true, solange ein Rezept offen ist
 * @returns {Promise<void>}
 */
async function setKeepAwake(on){
  keepAwake = on;
  if(!on){
    const lock = wakeLock;
    wakeLock = null;
    await lock?.release().catch(() => {});
    return;
  }
  if(wakeLock || document.visibilityState !== 'visible') return;
  try {
    const lock = await navigator.wakeLock?.request('screen') ?? null;
    // Inzwischen geschlossen oder schon eine Sperre da: diese gleich wieder freigeben, sonst bliebe sie bestehen.
    if(!keepAwake || wakeLock){
      await lock?.release();
      return;
    }
    wakeLock = lock;
    lock?.addEventListener('release', () => { if(wakeLock === lock) wakeLock = null; });
  } catch(err){ /* nicht unterstützt oder verweigert */ }
}

// Im Hintergrund gibt der Browser die Sperre selbst frei; beim Zurückkehren neu anfordern, wenn das Rezept noch offen ist.
document.addEventListener('visibilitychange', () => {
  if(document.visibilityState === 'visible' && keepAwake) void setKeepAwake(true);
});

/**
 * Maskiert die HTML-Sonderzeichen & < > " ' (für Text und Attributwerte).
 * @param {?string} str Beliebiger Text (null/undefined wird wie "" behandelt)
 * @returns {string} Der maskierte Text
 */
function escapeHtml(str){
  return (str||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

/**
 * Lädt die Rezeptliste von der JSON-API (api/recipes.php).
 * @returns {Promise<Recipe[]>} Alle Rezepte
 */
async function loadRecipes(){
  /** @type {{recipes: Recipe[]}} */
  const data = await fetch('api/recipes.php').then(res => res.json());
  return data.recipes;
}

/**
 * Liest das gespeicherte API-Token aus localStorage.
 * @returns {string} Das Token, oder "" falls keins gespeichert ist
 */
function getStoredToken(){
  return localStorage.getItem(TOKEN_KEY) || '';
}

/**
 * Löscht das gespeicherte API-Token aus localStorage.
 * @returns {void}
 */
function forgetToken(){
  localStorage.removeItem(TOKEN_KEY);
}

/**
 * Holt das gespeicherte API-Token oder fragt es einmalig per prompt() ab und merkt es sich in localStorage.
 * @returns {string} Das Token, oder "" falls der Nutzer keins eingegeben hat
 */
function requireToken(){
  let token = getStoredToken();
  if(!token){
    token = (window.prompt('API-Token für Änderungen eingeben:') || '').trim();
    if(token) localStorage.setItem(TOKEN_KEY, token);
  }
  return token;
}

/**
 * fetch()-Wrapper für schreibende API-Aufrufe: hängt das Token an, wirft bei
 * 401 (und vergisst dann das falsche Token) bzw. anderen Fehlerstatus mit der
 * Server-Fehlermeldung als Error.
 * @param {string} url Ziel-URL des API-Aufrufs
 * @param {RequestInit} [options] fetch()-Optionen (Methode, Body, Header); das Token wird ergänzt
 * @returns {Promise<any>} Der dekodierte JSON-Body der Antwort
 * @throws {Error} Ohne Token, bei 401 oder bei sonstigem Fehlerstatus
 */
async function apiWrite(url, options){
  const token = requireToken();
  if(!token){
    throw new Error('Ohne Token sind keine Änderungen möglich.');
  }
  options = options || {};
  options.headers = Object.assign({}, options.headers, {'X-API-Token': token});

  const res = await fetch(url, options);
  if(res.status === 401){
    forgetToken();
    throw new Error('Token wurde vom Server abgelehnt. Bitte erneut versuchen.');
  }
  const body = await res.json().catch(() => ({}));
  if(!res.ok){
    const msg = body.details ? body.details.join('; ') : (body.error || `HTTP ${res.status}`);
    throw new Error(msg);
  }
  return body;
}

/**
 * URL des Thumbnails (bzw. des Originals, falls kein Thumbnail existiert).
 * data/ liegt außerhalb des Document Root, Bilder laufen deshalb über api/image.php statt über eine statische URL.
 * @param {Recipe} r Das Rezept
 * @returns {?string} Die Bild-URL, oder null, wenn das Rezept kein Bild hat
 */
function thumbSrc(r){
  if(!r.image) return null;
  const type = r.thumb ? 'thumb' : 'image';
  return `api/image.php?slug=${encodeURIComponent(r.slug)}&type=${type}`;
}

/**
 * URL des Originalbilds eines Rezepts (über api/image.php).
 * @param {Recipe} r Das Rezept
 * @returns {?string} Die Bild-URL, oder null, wenn das Rezept kein Bild hat
 */
function imageSrc(r){
  return r.image ? `api/image.php?slug=${encodeURIComponent(r.slug)}&type=image` : null;
}

/**
 * Baut das HTML einer Rezeptkarte im Kartenraster.
 * @param {Recipe} r Das Rezept
 * @param {number} idx Index des Rezepts in RECIPES (landet in `data-idx` der Karte)
 * @returns {string} HTML der Karte
 */
function cardHtml(r, idx){
  const src = thumbSrc(r);
  const img = src ? `<img src="${escapeHtml(src)}" alt="${escapeHtml(r.title)}" loading="lazy">` : 'kein Foto';
  return `<div class="card" data-idx="${idx}">
    <div class="thumb">${img}</div>
    <div class="body">
      <h3>${escapeHtml(r.title)}</h3>
      <div class="meta">${escapeHtml(r.servings || '')}</div>
      ${r.category ? `<div class="cat-tag">${escapeHtml(r.category)}</div>` : ''}
    </div>
  </div>`;
}

/**
 * Alle bereits verwendeten Kategorien (aus dem aktuellen RECIPES-Stand), alphabetisch, ohne Duplikate.
 * @returns {string[]} Die Kategorienamen
 */
function knownCategories(){
  return [...new Set(RECIPES.map(r => r.category).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'de'));
}

/**
 * Baut die Kategorien-Filter-Optionen aus dem aktuellen RECIPES-Stand neu auf (nötig nach jedem
 * Anlegen/Bearbeiten/Löschen/Import, da dabei Kategorien hinzukommen oder wegfallen können).
 * Behält die aktuelle Auswahl bei, falls sie noch existiert, sonst wird sie auf "Alle Kategorien"
 * zurückgesetzt — eine Auswahl auf eine verschwundene Kategorie würde sonst zu einem leeren,
 * unerklärten Ergebnis führen.
 *
 * Bekannter Fehler bei Microsoft Edge für Android (Version 140.0.3485.98):
 * Die Vorschlagsliste erscheint und funktioniert, aber alle Optionen werden als leer angezeigt.
 * Stand 13. Okt. 2025: keine offizielle Lösung
 * https://learn.microsoft.com/en-us/answers/questions/5583325/datalist-tag-not-properly-rendered-on-microsoft-ed
 *
 * @returns {void}
 */
function refreshCategoryFilter(){
  const cats = knownCategories();
  const current = catSel.value;
  catSel.innerHTML = '<option value="">Alle Kategorien</option>' + cats.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
  catSel.value = cats.includes(current) ? current : '';
}

/**
 * Aktualisiert die Gesamtanzahl im Header (nötig nach jedem Anlegen/Löschen/Import).
 * @returns {void}
 */
function updateHeaderCount(){
  subCount.textContent = `${RECIPES.length} Rezepte`;
}

/**
 * Zeitstempel von r.created als Zahl für Sortierung. Fehlt/ungültig -> 0 (sortiert als ältestes).
 * @param {Recipe} r Das Rezept
 * @returns {number} Millisekunden seit 1970, oder 0
 */
function createdTime(r){
  const t = r.created ? new Date(r.created).getTime() : NaN;
  return isNaN(t) ? 0 : t;
}

/**
 * Filtert (Kategorie, Suchbegriff in Titel/Portionen/Zutaten/Schritten) und sortiert RECIPES gemäß den
 * aktuellen Eingaben und rendert das Ergebnis ins Kartenraster inkl. Trefferzähler.
 * @returns {void}
 */
function renderGrid(){
  const q = searchInput.value.trim().toLowerCase();
  const cat = catSel.value;
  let items = RECIPES.map((r, i) => ({r, i}));

  if(cat){
    items = items.filter(({r}) => r.category === cat);
  }

  if(q){
    items = items.filter(({r}) =>
      r.title.toLowerCase().includes(q)
      || (r.servings||'').toLowerCase().includes(q)
      || r.ingredients.some(g => g.text.some(t => t.toLowerCase().includes(q)))
      || r.steps.some(s => s.toLowerCase().includes(q))
    );
  }

  switch(sortSel.value){
    case 'title':
      items.sort((a,b) => a.r.title.localeCompare(b.r.title, 'de'));
      break;
    case 'category':
      // Rezepte ohne Kategorie ans Ende, innerhalb einer Kategorie alphabetisch nach Titel.
      items.sort((a,b) => {
        const catA = a.r.category, catB = b.r.category;
        if(!catA && !catB) return a.r.title.localeCompare(b.r.title, 'de');
        if(!catA) return 1;
        if(!catB) return -1;
        const catCompare = catA.localeCompare(catB, 'de');
        return catCompare !== 0 ? catCompare : a.r.title.localeCompare(b.r.title, 'de');
      });
      break;
    case 'created-asc':
      items.sort((a,b) => createdTime(a.r) - createdTime(b.r));
      break;
    case 'created-desc':
    default:
      items.sort((a,b) => createdTime(b.r) - createdTime(a.r));
      break;
  }

  resultCount.textContent = `${items.length} von ${RECIPES.length} Rezepten`;
  grid.innerHTML = items.length
    ? items.map(({r,i}) => cardHtml(r, i)).join('')
    : '<p class="empty">Keine Rezepte gefunden.</p>';
}

/**
 * Baut den Inhalt, der sowohl in der Lese-Ansicht als auch beim Drucken sichtbar ist
 * (Hero-Bild/Titel, Zutaten, Zubereitung, Notizen — ohne Toolbar/Schließen-Button).
 * Wird auch fürs unsichtbare Vermessen der Druckhöhe in printRecipe() wiederverwendet.
 * @param {Recipe} r Das Rezept
 * @returns {string} HTML des druckbaren Rezeptblatts
 */
function printableSheetHtml(r){
  const ingredientsHtml = r.ingredients.map(g => `
    ${g.group ? `<div class="group-label">${escapeHtml(g.group)}:</div>` : ''}
    <ul class="ingredients">${g.text.map(t => `<li>${escapeHtml(t)}</li>`).join('')}</ul>
  `).join('');

  const stepsHtml = r.steps.length
    ? `<h4>Zubereitung</h4><ol class="steps">${r.steps.map(s => `<li>${escapeHtml(s)}</li>`).join('')}</ol>`
    : '';

  const noteLines = r.notes ? r.notes.split('\n').map(s => s.trim()).filter(Boolean) : [];
  const notesHtml = noteLines.length
    ? `<h4>Notizen</h4><div class="notes">${noteLines.map(n => `<p>${escapeHtml(n)}</p>`).join('')}</div>`
    : '';

  const src = imageSrc(r);
  const imgHtml = src
    ? `<div class="hero-img"><div class="ratio-box"><img src="${escapeHtml(src)}" alt="${escapeHtml(r.title)}"></div></div>`
    : '';

  // Wird sowohl in der Leseansicht als auch im Ausdruck angezeigt (kein @media-print-Ausblenden mehr).
  const createdDate = r.created ? new Date(r.created) : null;
  const createdHtml = createdDate && !isNaN(createdDate.getTime())
    ? `<div class="created-date">Erstellt am ${escapeHtml(createdDate.toLocaleDateString('de-DE'))}</div>`
    : '';

  return `
    <div class="hero">
      ${imgHtml}
      <div class="titles">
        <h2>${escapeHtml(r.title)}</h2>
        <div class="persons">${escapeHtml(r.servings || '')}${r.category ? ' &middot; ' + escapeHtml(r.category) : ''}</div>
        ${createdHtml}
      </div>
    </div>
    <h4>Zutaten</h4>
    <div class="ingredients-wrap" id="ingredientsWrap">${ingredientsHtml || '<p><em>keine erfasst</em></p>'}</div>
    ${stepsHtml}
    ${notesHtml}
  `;
}

/**
 * Zeigt ein Rezept in der Lese-Ansicht (Overlay) mit den Buttons Drucken/Bearbeiten/Löschen.
 * @param {number} idx Index des Rezepts in RECIPES
 * @returns {void}
 */
function openRecipe(idx){
  const r = RECIPES[idx];
  formIsOpen = false;

  dialog.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <div class="btn-row toolbar">
      <button class="btn" id="printBtn" type="button">Drucken</button>
      <button class="btn" id="editBtn" type="button">Bearbeiten</button>
      <button class="btn btn-danger" id="deleteBtn" type="button">Löschen</button>
    </div>
    ${printableSheetHtml(r)}
  `;
  overlay.classList.add('open');
  document.getElementById('closeBtn').addEventListener('click', closeRecipe);
  document.getElementById('printBtn').addEventListener('click', () => printRecipe(r));
  document.getElementById('editBtn').addEventListener('click', () => openEditForm(r));
  document.getElementById('deleteBtn').addEventListener('click', () => deleteRecipeUI(r));
  void setKeepAwake(true);
}

/**
 * Setzt vor dem Drucken die zweispaltige Zutatenliste (nur beim Drucken sichtbar, siehe
 * .ingredients-wrap.two-col in @media print), falls das im Bearbeitungsformular gesetzte Flag
 * twoColumnPrint aktiv ist, und öffnet danach den Druckdialog. Keine automatische Schätzung mehr
 * (eine frühere Pixel-Messung im unsichtbaren DOM sowie eine Zeilen-Zeichen-Schätzung waren beide
 * unzuverlässig) — der Nutzer entscheidet das selbst pro Rezept.
 * @param {Recipe} r Das zu druckende (aktuell geöffnete) Rezept
 * @returns {void}
 */
function printRecipe(r){
  const wrap = document.getElementById('ingredientsWrap');
  if(wrap) wrap.classList.toggle('two-col', r.twoColumnPrint === true);

  window.print();
}

/**
 * Schließt das Overlay (Lese-Ansicht oder Formular) und leert den Inhalt.
 * @returns {void}
 */
function closeRecipe(){
  formIsOpen = false;
  void setKeepAwake(false);
  overlay.classList.remove('open');
  dialog.innerHTML = '';
}

/**
 * Formatiert die gruppierten ingredients (API-Format) als einfachen Text fürs Formular:
 * eine Zutat pro Zeile, eine Zeile mit führendem "#" markiert eine neue Gruppenüberschrift.
 * Vor jeder Gruppenüberschrift (außer ganz am Anfang) steht zur besseren Lesbarkeit eine
 * Leerzeile; parseIngredientsText() ignoriert Leerzeilen beim Zurückparsen wieder.
 * @param {IngredientGroup[]} ingredients Die gruppierten Zutaten
 * @returns {string} Der Text fürs Formularfeld
 */
function formatIngredientsText(ingredients){
  return ingredients.map((g, i) => {
    const lines = g.group ? [`# ${g.group}:`, ...g.text] : g.text.slice();
    if(g.group && i > 0) lines.unshift('');
    return lines.join('\n');
  }).join('\n');
}

/**
 * Nummeriert die Zubereitungsschritte fürs Formular durch. Umkehrfunktion zu parseStepsText().
 * @param {string[]} steps Die Schritte
 * @returns {string} Der Text fürs Formularfeld, ein nummerierter Schritt pro Zeile
 */
function formatStepsText(steps){
  return steps.map((s, i) => `${i + 1}. ${s}`).join('\n');
}

/**
 * Entfernt eine vom User eingegebene Nummerierung ("1.", "2)", ...) oder ein Listenzeichen
 * ("-", "*") am Zeilenanfang wieder – Bsp1–Bsp4 aus der Anfrage führen alle zum selben Ergebnis.
 * Leerzeilen werden ignoriert.
 * @param {string} text Der Inhalt des Schritte-Felds
 * @returns {string[]} Die Schritte ohne Nummerierung/Listenzeichen
 */
function parseStepsText(text){
  return text.split('\n')
    .map(s => s.trim().replace(/^(?:\d+[.)]|[-*])\s*/, ''))
    .filter(Boolean);
}

/**
 * Parst den einfachen Zutaten-Text zurück ins gruppierte API-Format. Zeilen mit führendem "#"
 * eröffnen eine neue Gruppe (Text danach = Gruppenname, ein optionaler abschließender Doppelpunkt
 * wird entfernt; "#" allein bzw. ohne Text danach = zurück zu "ohne Überschrift"). Leere Zeilen
 * und Gruppen ohne Zutaten werden verworfen.
 * @param {string} text Der Inhalt des Zutaten-Felds
 * @returns {IngredientGroup[]} Die gruppierten Zutaten
 */
function parseIngredientsText(text){
  const groups = [];
  let currentGroup = null;
  let bucket = [];
  const flush = () => {
    if(bucket.length) groups.push({group: currentGroup, text: bucket});
    bucket = [];
  };
  for(const raw of text.split('\n')){
    const line = raw.trim();
    if(line.startsWith('#')){
      flush();
      let name = line.slice(1).trim();
      if(name.endsWith(':')) name = name.slice(0, -1).trim();
      currentGroup = name || null;
    } else if(line){
      bucket.push(line);
    }
  }
  flush();
  return groups;
}

/**
 * Zeigt das Anlege-/Bearbeitungsformular.
 * @param {?Recipe} r Das zu bearbeitende Rezept; null bedeutet: neues Rezept
 * @returns {void}
 */
function openEditForm(r){
  formIsOpen = true;
  void setKeepAwake(false);
  const isEdit = r !== null;
  const ingredientsText = isEdit ? formatIngredientsText(r.ingredients) : '';
  const stepsText = isEdit ? formatStepsText(r.steps) : '';
  const notesText = isEdit ? (r.notes || '') : '';
  const src = isEdit ? imageSrc(r) : null;
  const previewHtml = src
    ? `<div class="form-preview"><div class="ratio-box"><img id="imgPreview" src="${escapeHtml(src)}" alt=""></div></div>`
    : `<div class="form-preview" id="previewWrap" style="display:none"><div class="ratio-box"><img id="imgPreview" src="" alt=""></div></div>`;

  dialog.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <h2>${isEdit ? 'Rezept bearbeiten' : 'Neues Rezept'}</h2>
    <div id="formError"></div>
    <div class="form-field">
      <label for="titleInput">Titel</label>
      <input type="text" id="titleInput" value="${isEdit ? escapeHtml(r.title) : ''}">
    </div>
    <div class="form-field">
      <label for="categoryInput">Kategorie</label>
      <input type="text" id="categoryInput" list="categoryList" autocomplete="off" value="${isEdit ? escapeHtml(r.category || '') : ''}">
      <datalist id="categoryList">${knownCategories().map(c => `<option value="${escapeHtml(c)}">`).join('')}</datalist>
    </div>
    <div class="form-field">
      <label for="servingsInput">Portionen</label>
      <input type="text" id="servingsInput" value="${isEdit ? escapeHtml(r.servings || '') : ''}" placeholder="z. B. für 4 Personen">
    </div>
    <div class="form-field">
      <label>Bild</label>
      ${previewHtml}
      <input type="file" id="imageInput" accept="image/jpeg,image/png,image/webp">
      <div class="hint">Wird erst nach dem Speichern hochgeladen. Erzeugt automatisch ein Thumbnail.</div>
    </div>
    <div class="form-field">
      <label for="ingredientsInput">Zutaten</label>
      <textarea id="ingredientsInput">${escapeHtml(ingredientsText)}</textarea>
      <div class="hint">Eine Zutat pro Zeile. Eine Zeile mit # markiert eine neue Gruppenüberschrift, z. B. "# Für die Soße:"</div>
    </div>
    <div class="form-field">
      <label for="stepsInput">Zubereitung (ein Schritt pro Zeile)</label>
      <textarea id="stepsInput">${escapeHtml(stepsText)}</textarea>
      <div class="hint">Nummerierung nur zur Übersicht, wird beim Speichern entfernt. "1.", "-" oder "*" am Zeilenanfang werden ignoriert.</div>
    </div>
    <div class="form-field">
      <label for="notesInput">Notizen</label>
      <textarea id="notesInput">${escapeHtml(notesText)}</textarea>
      <div class="hint">Freier Text. In der Leseansicht wird jede Zeile als eigener Absatz dargestellt.</div>
    </div>
    <div class="form-field checkbox-field">
      <label><input type="checkbox" id="twoColumnPrintInput" ${isEdit && r.twoColumnPrint ? 'checked' : ''}> Zutaten beim Drucken zweispaltig darstellen</label>
      <div class="hint">Sinnvoll bei langen Rezepten, die sonst nicht auf eine Druckseite passen.</div>
    </div>
    <div class="btn-row">
      <button class="btn btn-primary" id="saveBtn" type="button">Speichern</button>
      <button class="btn" id="cancelBtn" type="button">Abbrechen</button>
    </div>
  `;
  overlay.classList.add('open');

  document.getElementById('closeBtn').addEventListener('click', closeRecipe);
  document.getElementById('cancelBtn').addEventListener('click', () => {
    isEdit ? openRecipe(RECIPES.indexOf(r)) : closeRecipe();
  });
  document.getElementById('imageInput').addEventListener('change', e => {
    const file = e.target.files[0];
    if(!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      let wrap = document.getElementById('previewWrap');
      if(wrap){ wrap.style.display = ''; }
      document.getElementById('imgPreview').src = reader.result;
    };
    reader.readAsDataURL(file);
  });
  document.getElementById('saveBtn').addEventListener('click', () => saveRecipe(isEdit ? r.slug : null));
}

/**
 * Liest die Formularfelder aus und baut daraus den API-Payload (ohne leere Zutatengruppen/-zeilen).
 * @returns {{title: string, category: string, servings: string, ingredients: IngredientGroup[],
 *            steps: string[], notes: ?string, twoColumnPrint: boolean}} Der Payload für POST/PUT api/recipes.php
 */
function collectFormData(){
  const title = document.getElementById('titleInput').value.trim();
  const category = document.getElementById('categoryInput').value.trim();
  const servings = document.getElementById('servingsInput').value.trim();

  const ingredients = parseIngredientsText(document.getElementById('ingredientsInput').value);

  const steps = parseStepsText(document.getElementById('stepsInput').value);
  const notes = document.getElementById('notesInput').value.trim() || null;
  const twoColumnPrint = document.getElementById('twoColumnPrintInput').checked;

  return {title, category, servings, ingredients, steps, notes, twoColumnPrint};
}

/**
 * Speichert das Formular (Anlegen oder Ändern), lädt danach ggf. das gewählte Bild hoch und zeigt das
 * gespeicherte Rezept an. Fehler werden im Formular angezeigt; ein Bild-Upload-Fehler erst nach dem Speichern.
 * @param {?string} slug Slug des bearbeiteten Rezepts; null legt ein neues Rezept an
 * @returns {Promise<void>}
 */
async function saveRecipe(slug){
  const payload = collectFormData();
  const errorBox = document.getElementById('formError');
  errorBox.innerHTML = '';

  if(!payload.title){
    errorBox.innerHTML = '<div class="error-box">Titel ist erforderlich.</div>';
    return;
  }

  const saveBtn = document.getElementById('saveBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Speichert …';

  /** @type {Recipe} */
  let saved;
  try {
    const url = slug ? `api/recipes.php?slug=${encodeURIComponent(slug)}` : 'api/recipes.php';
    saved = await apiWrite(url, {
      method: slug ? 'PUT' : 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
    });
  } catch(err){
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(err.message)}</div>`;
    saveBtn.disabled = false;
    saveBtn.textContent = 'Speichern';
    return;
  }

  // Text-Teil ist ab hier gespeichert. Ein evtl. folgender Bild-Upload-Fehler
  // darf diesen Erfolg nicht mehr verschlucken (sonst wirkt es, als sei nichts gespeichert).
  let imageError = null;
  const fileInput = document.getElementById('imageInput');
  if(fileInput.files[0]){
    try {
      const fd = new FormData();
      fd.append('slug', saved.slug);
      fd.append('image', fileInput.files[0]);
      saved = await apiWrite('api/upload.php', {method: 'POST', body: fd});
    } catch(err){
      imageError = err.message;
    }
  }

  RECIPES = RECIPES.filter(x => x.slug !== slug && x.slug !== saved.slug);
  RECIPES.push(saved);
  refreshCategoryFilter();
  updateHeaderCount();
  renderGrid();
  openRecipe(RECIPES.indexOf(saved));
  if(imageError){
    alert('Rezept gespeichert, aber Bild-Upload fehlgeschlagen: ' + imageError);
  }
}

/**
 * Löscht ein Rezept nach Rückfrage und aktualisiert die Oberfläche.
 * @param {Recipe} r Das zu löschende Rezept
 * @returns {Promise<void>}
 */
async function deleteRecipeUI(r){
  if(!window.confirm(`"${r.title}" wirklich unwiderruflich löschen?`)) return;
  try {
    await apiWrite(`api/recipes.php?slug=${encodeURIComponent(r.slug)}`, {method: 'DELETE'});
    RECIPES = RECIPES.filter(x => x.slug !== r.slug);
    refreshCategoryFilter();
    updateHeaderCount();
    closeRecipe();
    renderGrid();
  } catch(err){
    alert('Löschen fehlgeschlagen: ' + err.message);
  }
}

grid.addEventListener('click', e => {
  const card = e.target.closest('.card');
  if(card) openRecipe(parseInt(card.dataset.idx, 10));
});
overlay.addEventListener('click', e => {
  if(e.target === overlay && !formIsOpen) closeRecipe();
});
document.addEventListener('keydown', e => {
  if(e.key === 'Escape' && !formIsOpen) closeRecipe();
});
/** Schlüssel in localStorage, unter dem Suchbegriff, Kategorie-Filter und Sortierung abgelegt werden. @type {string} */
const VIEW_STATE_KEY = 'kochbuch_view_state';

/**
 * Speichert Suchbegriff, Kategorie-Filter und Sortierung, damit sie einen Reload überstehen.
 * @returns {void}
 */
function saveViewState(){
  localStorage.setItem(VIEW_STATE_KEY, JSON.stringify({
    search: searchInput.value,
    category: catSel.value,
    sort: sortSel.value,
  }));
}

/**
 * Liest den mit saveViewState() gespeicherten Zustand.
 * @returns {{search?: string, category?: string, sort?: string}} Der Zustand; leeres Objekt, falls nicht lesbar
 */
function loadViewState(){
  try {
    return JSON.parse(localStorage.getItem(VIEW_STATE_KEY) || '{}');
  } catch(err) {
    return {};
  }
}

/**
 * Handler für Änderungen an Suche, Sortierung und Kategorie: Zustand speichern, Raster neu rendern.
 * @returns {void}
 */
function onFilterChange(){
  saveViewState();
  renderGrid();
}

searchInput.addEventListener('input', onFilterChange);
sortSel.addEventListener('change', onFilterChange);
catSel.addEventListener('change', onFilterChange);
newRecipeBtn.addEventListener('click', () => openEditForm(null));
importRecipeBtn.addEventListener('click', () => openImportForm());

/**
 * Zeigt das Formular zum Rezept-Import per URL (chefkoch.de u. Ä. mit schema.org/Recipe).
 * @returns {void}
 */
function openImportForm(){
  formIsOpen = true;
  void setKeepAwake(false);
  dialog.innerHTML = `
    <button class="close" id="closeBtn" aria-label="Schließen">&times;</button>
    <h2>Rezept importieren</h2>
    <div id="formError"></div>
    <div class="form-field">
      <label for="importUrlInput">URL</label>
      <input type="text" id="importUrlInput" placeholder="https://www.chefkoch.de/rezepte/...">
      <div class="hint">Funktioniert bei
        <a href="https://www.chefkoch.de" target="_blank">chefkoch.de</a>,
        <a href="https://www.lecker.de" target="_blank">lecker.de</a>
        und andere Rezeptseiten mit schema.org/Recipe-Daten.
      </div>
    </div>
    <div class="btn-row">
      <button class="btn btn-primary" id="importBtn" type="button">Importieren</button>
      <button class="btn" id="cancelBtn" type="button">Abbrechen</button>
    </div>
  `;
  overlay.classList.add('open');
  document.getElementById('closeBtn').addEventListener('click', closeRecipe);
  document.getElementById('cancelBtn').addEventListener('click', closeRecipe);
  document.getElementById('importBtn').addEventListener('click', runImport);
}

/**
 * Importiert das Rezept von der eingegebenen URL (POST api/import.php) und zeigt es danach an.
 * Fehler werden im Formular angezeigt.
 * @returns {Promise<void>}
 */
async function runImport(){
  const url = document.getElementById('importUrlInput').value.trim();
  const errorBox = document.getElementById('formError');
  errorBox.innerHTML = '';

  if(!url){
    errorBox.innerHTML = '<div class="error-box">URL ist erforderlich.</div>';
    return;
  }

  const importBtn = document.getElementById('importBtn');
  importBtn.disabled = true;
  importBtn.textContent = 'Importiert …';

  try {
    /** @type {Recipe} */
    const saved = await apiWrite('api/import.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({url}),
    });
    RECIPES = RECIPES.filter(x => x.slug !== saved.slug);
    RECIPES.push(saved);
    refreshCategoryFilter();
    updateHeaderCount();
    renderGrid();
    openRecipe(RECIPES.indexOf(saved));
    if(saved.import_warning){
      alert(saved.import_warning);
    }
  } catch(err){
    errorBox.innerHTML = `<div class="error-box">${escapeHtml(err.message)}</div>`;
    importBtn.disabled = false;
    importBtn.textContent = 'Importieren';
  }
}

/**
 * Startet die App: lädt die Rezepte, baut den Kategorie-Filter auf, stellt Suchbegriff/Filter/Sortierung
 * vom letzten Besuch wieder her und rendert das Kartenraster.
 * @returns {Promise<void>}
 */
async function init(){
  try {
    RECIPES = await loadRecipes();
  } catch(err) {
    console.error(err);
    subCount.textContent = 'Rezepte konnten nicht geladen werden.';
    grid.innerHTML = '<p class="empty">Fehler beim Laden der Rezepte.</p>';
    return;
  }

  refreshCategoryFilter();
  updateHeaderCount();

  // Suchbegriff/Filter/Sortierung vom letzten Besuch wiederherstellen. Kategorie nur übernehmen,
  // wenn es diese Option nach dem Neuaufbau der Liste (refreshCategoryFilter) noch gibt — sonst
  // bleibt es bei "Alle Kategorien", statt eine verschwundene Kategorie stumm auszuwählen.
  const saved = loadViewState();
  if(saved.search) searchInput.value = saved.search;
  if(saved.sort && [...sortSel.options].some(o => o.value === saved.sort)) sortSel.value = saved.sort;
  if(saved.category && [...catSel.options].some(o => o.value === saved.category)) catSel.value = saved.category;

  renderGrid();
}

void init();
