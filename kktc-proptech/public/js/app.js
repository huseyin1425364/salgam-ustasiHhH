// public/js/app.js

let map;
let markersMap = {};
let campusMarkersGroup = null;
let currentCampusId = 1;
let selectedProperties = new Set();
let propertiesList = [];

let streetLayer;
let satelliteLayer;
let currentLayer = 'street';

let isPickingLocation = false;
let pickedLocation = null;
let tempPickMarker = null;

let currentDetailPropertyId = null;
let currentDetailOwnerId = null;
let activePropertyId = null;

let currentUser = null;
let pendingOtpUserId = null;
let viewedProfileData = null;
let allAdminProperties = [];

// =========================================================================
// MODERN BİLDİRİM MOTORU: Alt-Orta (Bottom-Center) Dinamik Hap (Pill) Toast
// =========================================================================
const Toast = Swal.mixin({
  toast: true,
  position: 'bottom',
  showConfirmButton: false,
  timer: 3500,
  timerProgressBar: true,
  background: '#0f172a',
  color: '#ffffff',
  customClass: {
    popup: 'modern-toast-pill',
    timerProgressBar: 'bg-blue-500'
  },
  showClass: { popup: 'animate__animated animate__fadeInUp animate__faster' },
  hideClass: { popup: 'animate__animated animate__fadeOutDown animate__faster' },
  didOpen: (toast) => {
    toast.addEventListener('mouseenter', Swal.stopTimer);
    toast.addEventListener('mouseleave', Swal.resumeTimer);
  }
});

const notify = {
  success: (title, text = '') => {
    Toast.fire({
      icon: 'success',
      iconColor: '#10b981',
      title: `<span style="font-weight:700; font-size:12px;">${title}</span> ${text ? `<span style="font-weight:400; font-size:11px; color:#94a3b8; margin-left:6px;">${text}</span>` : ''}`
    });
  },
  error: (title, text = '') => {
    Toast.fire({
      icon: 'error',
      iconColor: '#ef4444',
      title: `<span style="font-weight:700; font-size:12px;">${title}</span> ${text ? `<span style="font-weight:400; font-size:11px; color:#94a3b8; margin-left:6px;">${text}</span>` : ''}`
    });
  },
  warning: (title, text = '') => {
    Toast.fire({
      icon: 'warning',
      iconColor: '#f59e0b',
      title: `<span style="font-weight:700; font-size:12px;">${title}</span> ${text ? `<span style="font-weight:400; font-size:11px; color:#94a3b8; margin-left:6px;">${text}</span>` : ''}`
    });
  },
  info: (title, text = '') => {
    Toast.fire({
      icon: 'info',
      iconColor: '#3b82f6',
      title: `<span style="font-weight:700; font-size:12px;">${title}</span> ${text ? `<span style="font-weight:400; font-size:11px; color:#94a3b8; margin-left:6px;">${text}</span>` : ''}`
    });
  },
  confirm: async (title, text, confirmButtonText = 'Evet, Onaylıyorum') => {
    const result = await Swal.fire({
      title: title,
      text: text,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#2563eb',
      cancelButtonColor: '#94a3b8',
      confirmButtonText: confirmButtonText,
      cancelButtonText: 'İptal',
      reverseButtons: true,
      customClass: {
        popup: 'rounded-3xl shadow-2xl',
        confirmButton: 'rounded-xl text-xs font-bold px-4 py-2.5',
        cancelButton: 'rounded-xl text-xs font-bold px-4 py-2.5'
      }
    });
    return result.isConfirmed;
  }
};

function showModal(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'flex';
}

function hideModal(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
  lucide.createIcons();
  checkUserSession();
  initMap();
  fetchProperties();
  setupFilterEvents();
  setupNlpSearch();
  setupNewPropertyModule();
  setupDetailModalEvents();
  setupAuthModule();
  setupUserProfileModal();
  setupMapLayerSwitcher();
  setupAdminModule();
  setupPropertyEditModule();
});

function resolveImageUrl(url) {
  if (!url) return 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=80';
  if (url.startsWith('http://') || url.startsWith('https://')) return url;
  return url;
}

// 1. Kullanıcı Oturumu Kontrolü
function checkUserSession() {
  const saved = localStorage.getItem('islandprop_user');
  if (saved) {
    try {
      currentUser = JSON.parse(saved);
      renderAuthNavbar(currentUser);
      if (['admin', 'superadmin'].includes(currentUser.role)) {
        checkAdminPendingCount();
      }
    } catch (e) {
      localStorage.removeItem('islandprop_user');
    }
  }
}

function renderAuthNavbar(user) {
  const guestSec = document.getElementById('authGuestSection');
  const userSec = document.getElementById('authUserSection');
  const adminBtn = document.getElementById('adminPanelOpenBtn');
  const adminLabel = document.getElementById('adminBtnLabel');

  if (!guestSec || !userSec) return;

  if (user) {
    guestSec.style.display = 'none';
    userSec.style.display = 'flex';

    document.getElementById('userNavName').innerText = user.full_name;
    const roleLabels = { student: 'Öğrenci', landlord: 'Ev Sahibi', agent: 'Emlak Ofisi', admin: 'Yönetici', superadmin: 'Sistem Yöneticisi' };
    document.getElementById('userNavRole').innerText = roleLabels[user.role] || user.role;
    if (user.avatar_url) document.getElementById('userNavAvatar').src = user.avatar_url;

    const trigger = document.getElementById('navProfileTrigger');
    if (trigger) trigger.onclick = () => openUserProfileModal(user.id);

    if (adminBtn) {
      if (user.role === 'superadmin') {
        adminBtn.style.display = 'inline-flex';
        adminLabel.innerText = "🛡️ Sistem Yönetim Paneli";
      } else if (user.role === 'admin') {
        adminBtn.style.display = 'inline-flex';
        adminLabel.innerText = "Yönetim Paneli";
      } else {
        adminBtn.style.display = 'none';
      }
    }
  } else {
    guestSec.style.display = 'block';
    userSec.style.display = 'none';
    if (adminBtn) adminBtn.style.display = 'none';
  }
}

// 2. Yetkiliye Onay Bekleyen İlan Bildirim Kontrolü
async function checkAdminPendingCount() {
  if (!currentUser || !['admin', 'superadmin'].includes(currentUser.role)) return;
  try {
    const res = await fetch(`../backend/api/admin/manager.php?auth_user_id=${currentUser.id}`);
    const raw = await res.text();
    if (!raw) return;
    const data = JSON.parse(raw);

    if (data.status === 'success') {
      const count = parseInt(data.pending_count || 0);
      const badge = document.getElementById('adminNotificationBadge');
      const tabBadge = document.getElementById('adminTabBadge');

      if (count > 0) {
        if (badge) {
          badge.innerText = count;
          badge.style.display = 'flex';
        }
        if (tabBadge) {
          tabBadge.innerText = count;
          tabBadge.style.display = 'inline-block';
        }
      } else {
        if (badge) badge.style.display = 'none';
        if (tabBadge) tabBadge.style.display = 'none';
      }
    }
  } catch (e) {
    console.error("Bildirim rozeti kontrol hatası:", e);
  }
}

// 3. Harita Başlatıcı (DAÜ Merkezli)
function initMap() {
  map = L.map('map', {
    zoomControl: false,
    attributionControl: false,
    maxZoom: 20,
    minZoom: 11
  }).setView([35.1424, 33.9117], 15);

  L.control.zoom({ position: 'bottomright' }).addTo(map);
  campusMarkersGroup = L.layerGroup().addTo(map);

  streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 20, subdomains: ['a', 'b', 'c'] });
  satelliteLayer = L.tileLayer('https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', { maxZoom: 20, subdomains: ['0', '1', '2', '3'] });
  streetLayer.addTo(map);

  map.on('click', (e) => {
    if (isPickingLocation) {
      pickedLocation = e.latlng;
      if (tempPickMarker) map.removeLayer(tempPickMarker);

      const pinIcon = L.divIcon({
        className: 'new-pick-pin',
        html: `<div class="w-8 h-8 rounded-full bg-red-600 border-2 border-white shadow-xl flex items-center justify-center text-white animate-bounce"><i data-lucide="map-pin" style="width:16px;height:16px;"></i></div>`,
        iconSize: [32, 32],
        iconAnchor: [16, 32]
      });

      tempPickMarker = L.marker([pickedLocation.lat, pickedLocation.lng], { icon: pinIcon }).addTo(map);
      lucide.createIcons();

      document.getElementById('mapPickingAlert').style.display = 'none';
      document.getElementById('selectedCoordsText').innerText = `${pickedLocation.lat.toFixed(4)}, ${pickedLocation.lng.toFixed(4)}`;
      document.getElementById('selectedCoordsText').className = "text-[11px] text-emerald-600 font-bold";

      isPickingLocation = false;
      showModal('newPropertyModal');
      notify.success("Konum İşaretlendi", "Harita koordinatları başarıyla kaydedildi.");
      return;
    }

    if (!e.originalEvent.target.closest('.property-pin-wrap')) {
      clearPropertySelection();
    }
  });
}

function setupMapLayerSwitcher() {
  const streetBtn = document.getElementById('layerStreetsBtn');
  const satBtn = document.getElementById('layerSatelliteBtn');
  if (!streetBtn || !satBtn) return;

  streetBtn.addEventListener('click', () => {
    if (currentLayer === 'street') return;
    map.removeLayer(satelliteLayer);
    streetLayer.addTo(map);
    currentLayer = 'street';
    streetBtn.className = "px-3 py-1.5 rounded-xl text-xs font-extrabold transition bg-blue-600 text-white shadow-sm flex items-center gap-1.5";
    satBtn.className = "px-3 py-1.5 rounded-xl text-xs font-extrabold transition text-slate-600 hover:text-slate-900 hover:bg-slate-100 flex items-center gap-1.5";
  });

  satBtn.addEventListener('click', () => {
    if (currentLayer === 'satellite') return;
    map.removeLayer(streetLayer);
    satelliteLayer.addTo(map);
    currentLayer = 'satellite';
    satBtn.className = "px-3 py-1.5 rounded-xl text-xs font-extrabold transition bg-blue-600 text-white shadow-sm flex items-center gap-1.5";
    streetBtn.className = "px-3 py-1.5 rounded-xl text-xs font-extrabold transition text-slate-600 hover:text-slate-900 hover:bg-slate-100 flex items-center gap-1.5";
  });
}

// 4. İlanları Çekme (Sadece Onaylı / status='active' Olanlar)
async function fetchProperties() {
  const priceInput = document.getElementById('filterPrice').value.trim();
  const bedrooms = document.getElementById('filterBedrooms').value;
  const walk = document.getElementById('filterWalk').value;

  const params = new URLSearchParams();
  params.append('campus_id', currentCampusId);

  if (priceInput !== '' && !isNaN(priceInput)) params.append('max_price', priceInput);
  if (bedrooms !== '') params.append('bedrooms', bedrooms);
  if (walk !== '') params.append('max_walk', walk);

  try {
    document.getElementById('resultsText').innerText = "Aranıyor...";
    const res = await fetch(`../backend/api/properties.php?${params.toString()}`);
    const data = await res.json();

    if (data.status === 'success') {
      propertiesList = data.properties || [];
      updateCampusDropdown(data.campus_points || [], data.active_reference);
      renderCampusCenter(data.campus_points || [], data.active_reference);
      renderCards(propertiesList);
      renderMapPins(propertiesList);
      document.getElementById('resultsText').innerText = `${propertiesList.length} Aktif İlan Yayında`;
    } else {
      document.getElementById('resultsText').innerText = data.message || "Hata oluştu";
    }
  } catch (error) {
    document.getElementById('resultsText').innerText = `Hata: ${error.message}`;
  }
}

function updateCampusDropdown(campusPoints, activeRef) {
  const select = document.getElementById('campusSelect');
  if (!select) return;

  if (select.children.length <= 1) {
    select.innerHTML = '';
    campusPoints.forEach(point => {
      const opt = document.createElement('option');
      opt.value = point.id;
      opt.innerText = point.name;
      if (point.id === activeRef.id) opt.selected = true;
      select.appendChild(opt);
    });
  }
}

function renderCampusCenter(campusPoints, activeRef) {
  if (!campusMarkersGroup) return;
  campusMarkersGroup.clearLayers();

  const centerPoint = (campusPoints && campusPoints.length > 0) ? campusPoints[0] : activeRef;
  if (!centerPoint) return;

  const lat = parseFloat(centerPoint.lat);
  const lng = parseFloat(centerPoint.lng);

  const iconHtml = `
    <div class="flex flex-col items-center cursor-pointer select-none" style="transform: translate(-50%, -100%);">
      <span class="px-2.5 py-1 rounded-xl shadow-lg text-[11px] font-black tracking-wide uppercase whitespace-nowrap mb-1 bg-blue-600 text-white ring-2 ring-white">
        🎓 DAÜ KAMPÜS MERKEZİ
      </span>
      <div class="w-10 h-10 rounded-full bg-blue-600 ring-4 ring-blue-300 campus-pulse text-white flex items-center justify-center shadow-2xl border-2 border-white">
        <i data-lucide="graduation-cap" style="width:20px;height:20px;"></i>
      </div>
      <div class="w-2.5 h-3 bg-blue-600 rotate-45 -mt-1.5 shadow"></div>
    </div>
  `;

  const icon = L.divIcon({ className: 'campus-center-marker', html: iconHtml, iconSize: [0, 0], iconAnchor: [0, 0] });
  L.marker([lat, lng], { icon: icon }).addTo(campusMarkersGroup);
  lucide.createIcons();
}

// 5. İlan Kartlarını Listeleme
function renderCards(properties) {
  const container = document.getElementById('propertiesContainer');
  container.innerHTML = '';

  if (!properties || properties.length === 0) {
    container.innerHTML = `
      <div class="py-16 text-center text-slate-400">
        <i data-lucide="home" class="w-10 h-10 mx-auto mb-2 text-slate-300 stroke-1"></i>
        <p class="text-xs font-semibold">Bu filtrelere uygun onaylı ilan bulunamadı.</p>
      </div>
    `;
    lucide.createIcons();
    return;
  }

  properties.forEach(item => {
    const isChecked = selectedProperties.has(item.id);
    const card = document.createElement('div');
    card.id = `card-${item.id}`;
    card.className = `p-3.5 bg-white rounded-2xl border border-slate-200/80 transition-all duration-200 hover:border-slate-300 cursor-pointer ${isChecked ? 'ring-2 ring-blue-600 border-transparent' : ''}`;

    const coverUrl = resolveImageUrl(item.cover_image);

    card.innerHTML = `
      <div class="flex gap-3.5">
        <div class="relative w-28 h-28 shrink-0 rounded-xl overflow-hidden bg-slate-100 flex items-center justify-center">
          <img src="${coverUrl}" alt="${item.title}" class="w-full h-full object-cover">
          <div class="absolute top-1.5 left-1.5 bg-slate-900/80 backdrop-blur-md text-white text-[10px] font-bold px-2 py-0.5 rounded-md">
            £${Math.round(item.price)}
          </div>
        </div>

        <div class="flex-1 min-w-0 flex flex-col justify-between">
          <div>
            <div class="flex items-start justify-between gap-1 mb-1">
              <h4 class="text-xs font-bold text-slate-800 line-clamp-1">${item.title}</h4>
              <input type="checkbox" data-id="${item.id}" ${isChecked ? 'checked' : ''} class="compare-checkbox w-4 h-4 rounded text-blue-600 border-slate-300 focus:ring-blue-500 cursor-pointer">
            </div>
            <p class="text-[11px] font-medium text-slate-500 mb-1.5 truncate">${item.district_name} • ${item.bedrooms}+1 • ${item.area_sqm} m²</p>
          </div>

          <div class="space-y-1.5">
            <div class="flex items-center gap-1.5 flex-wrap">
              <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-md ${item.analytics && item.analytics.is_walkable ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}">
                <i data-lucide="footprints" style="width:11px;height:11px;"></i>
                ${item.analytics ? item.analytics.walk_time_min : '--'} dk yürüme
              </span>

              <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-md bg-blue-50 text-blue-700">
                <i data-lucide="car" style="width:11px;height:11px;"></i>
                ${item.analytics ? item.analytics.drive_time_min : '--'} dk sürüş
              </span>
            </div>

            <div class="flex items-center justify-between text-[11px] text-slate-500 pt-1.5 border-t border-slate-100 gap-1">
              <button data-detail-id="${item.id}" class="detail-btn flex items-center gap-1 text-[10px] font-bold bg-slate-100 hover:bg-slate-200 text-slate-800 px-2.5 py-1 rounded-md transition shadow-2xs">
                <i data-lucide="info" style="width:11px;height:11px;"></i>
                Ayrıntılar
              </button>

              <button data-single-ai="${item.id}" class="single-ai-btn flex items-center gap-1 text-[10px] font-bold bg-amber-50 hover:bg-amber-100 text-amber-800 px-2 py-0.5 rounded-md transition border border-amber-200/60 shadow-2xs">
                <i data-lucide="sparkles" style="width:11px;height:11px;" class="text-amber-500"></i>
                AI Çevre
              </button>
            </div>
          </div>
        </div>
      </div>
    `;

    card.addEventListener('click', (e) => {
      if (e.target.closest('.compare-checkbox') || e.target.closest('.single-ai-btn') || e.target.closest('.detail-btn')) return;
      focusProperty(item.id, item.lat, item.lng);
    });

    container.appendChild(card);
  });

  lucide.createIcons();
  attachCheckboxEvents();
  attachSingleAiEvents();
  attachDetailButtonEvents();
}

// 6. Harita Fiyat Pinleri
function renderMapPins(properties) {
  Object.values(markersMap).forEach(m => map.removeLayer(m));
  markersMap = {};

  if (!properties) return;

  properties.forEach(item => {
    const isCurrentActive = (activePropertyId === item.id);
    const pinHtml = `
      <div class="property-pin-wrap pin-${item.id} ${isCurrentActive ? 'active' : ''}">
        <div class="property-pin-body">
          <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-amber-400">
            <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
          <span>£${Math.round(item.price)}</span>
        </div>
        <div class="property-pin-tail"></div>
      </div>
    `;

    const pinIcon = L.divIcon({ className: 'property-leaflet-marker', html: pinHtml, iconSize: [0, 0], iconAnchor: [0, 0] });
    const marker = L.marker([item.lat, item.lng], { icon: pinIcon }).addTo(map);

    marker.on('click', () => {
      focusCardFromMap(item.id);
      focusProperty(item.id, item.lat, item.lng, false);
    });

    markersMap[item.id] = marker;
  });
}

function focusProperty(id, lat, lng, moveMap = true) {
  activePropertyId = id;
  if (moveMap && lat && lng) {
    map.panTo([lat, lng], { animate: true, duration: 0.6 });
    if (map.getZoom() < 16) map.setZoom(16);
  }
  document.querySelectorAll('.property-pin-wrap.active').forEach(p => p.classList.remove('active'));
  if (id !== null) {
    const targetPin = document.querySelector(`.pin-${id}`);
    if (targetPin) targetPin.classList.add('active');
  }
  document.querySelectorAll('[id^="card-"]').forEach(c => c.classList.remove('card-focused'));
  if (id !== null) {
    const targetCard = document.getElementById(`card-${id}`);
    if (targetCard) targetCard.classList.add('card-focused');
  }
}

function clearPropertySelection() {
  activePropertyId = null;
  document.querySelectorAll('.property-pin-wrap.active').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('[id^="card-"]').forEach(c => c.classList.remove('card-focused'));
}

function focusCardFromMap(id) {
  const card = document.getElementById(`card-${id}`);
  if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function attachCheckboxEvents() {
  document.querySelectorAll('.compare-checkbox').forEach(cb => {
    cb.addEventListener('change', (e) => {
      const id = parseInt(e.target.dataset.id);
      if (e.target.checked) selectedProperties.add(id);
      else selectedProperties.delete(id);
      document.getElementById('compareCount').innerText = selectedProperties.size;
    });
  });
}

function attachSingleAiEvents() {
  document.querySelectorAll('.single-ai-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      runSinglePropertyAnalysis(parseInt(btn.dataset.singleAi));
    });
  });
}

function attachDetailButtonEvents() {
  document.querySelectorAll('.detail-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      openPropertyDetailModal(parseInt(btn.dataset.detailId));
    });
  });
}

// 7. İlan Detayları Modalı
async function openPropertyDetailModal(propertyId) {
  currentDetailPropertyId = propertyId;
  showModal('propertyDetailModal');

  document.getElementById('detailTitle').innerText = "Yükleniyor...";
  document.getElementById('detailDescription').innerText = "Detaylar yükleniyor...";
  document.getElementById('detailThumbnails').innerHTML = '';

  try {
    const res = await fetch(`../backend/api/property_detail.php?id=${propertyId}&campus_id=${currentCampusId}`);
    const data = await res.json();

    if (data.status === 'success' && data.property) {
      const p = data.property;
      const a = p.analytics || {};
      const fin = a.financials || {};
      const owner = p.owner || {};

      currentDetailOwnerId = owner.id;

      document.getElementById('detailTitle').innerText = p.title || "İsimsiz İlan";
      document.getElementById('detailPrice').innerText = Math.round(p.price || 0);
      document.getElementById('detailPeriodTag').innerText = p.payment_period_label || 'Aylık Ödeme';
      document.getElementById('detailBadge').innerText = `${p.district_name || 'Gazimağusa'} / ${p.city_name || 'KKTC'}`;
      document.getElementById('detailDescription').innerText = p.description || "Açıklama belirtilmemiş.";

      document.getElementById('detailWalkMin').innerText = `${a.walk_time_min || 0} Dakika`;
      document.getElementById('detailDriveMin').innerText = `${a.drive_time_min || 0} Dakika`;
      document.getElementById('detailDistance').innerText = `${a.distance_real_km || '0.0'} km`;
      document.getElementById('detailTotalCost').innerText = `£${fin.total_living_cost_gbp || Math.round(p.price || 0)} /ay`;

      document.getElementById('detailOwnerName').innerText = owner.full_name || 'Yetkili Ev Sahibi';
      document.getElementById('detailOwnerLocation').innerText = owner.city_region || 'Gazimağusa / KKTC';
      const roleNames = { student: 'Öğrenci', landlord: 'Ev Sahibi', agent: 'Emlak Ofisi', admin: 'Yönetici', superadmin: 'Sistem Yöneticisi' };
      document.getElementById('detailOwnerRole').innerText = roleNames[owner.role] || owner.role;
      if (owner.avatar_url) document.getElementById('detailOwnerAvatar').src = owner.avatar_url;

      const messageBtn = document.getElementById('messageOwnerBtn');
      const selfNotice = document.getElementById('selfListingNotice');
      const isMyOwnListing = (currentUser && currentUser.id === owner.id);

      if (isMyOwnListing) {
        messageBtn.style.display = 'none';
        selfNotice.style.display = 'inline-block';
      } else {
        messageBtn.style.display = 'inline-flex';
        selfNotice.style.display = 'none';
      }

      const mainImg = document.getElementById('detailMainImage');
      const thumbContainer = document.getElementById('detailThumbnails');
      thumbContainer.innerHTML = '';

      const images = (p.images && p.images.length > 0) ? p.images : [{ image_url: p.cover_image || '' }];
      mainImg.src = resolveImageUrl(images[0].image_url);

      images.forEach(img => {
        const url = resolveImageUrl(img.image_url);
        const thumb = document.createElement('img');
        thumb.src = url;
        thumb.className = "w-20 h-16 object-cover rounded-xl border border-slate-200 cursor-pointer hover:opacity-80 transition shrink-0";
        thumb.addEventListener('click', () => { mainImg.src = url; });
        thumbContainer.appendChild(thumb);
      });

      const isFurnished = parseInt(p.is_furnished) === 1;
      const isInverter = parseInt(p.has_inverter_ac) === 1;

      const grid = document.getElementById('detailFeaturesGrid');
      grid.innerHTML = `
        <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl">
          <span class="block text-slate-400 text-[10px] font-bold uppercase">Oda & Alan</span>
          <strong class="text-slate-800 font-bold">${p.bedrooms || 1}+1 (${p.area_sqm || 55} m²)</strong>
        </div>
        <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl">
          <span class="block text-slate-400 text-[10px] font-bold uppercase">Depozito Tutarı</span>
          <strong class="text-slate-800 font-bold">£${p.deposit_amount || 0} Sterlin</strong>
        </div>
        <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl">
          <span class="block text-slate-400 text-[10px] font-bold uppercase">Aylık Aidat</span>
          <strong class="text-slate-800 font-bold">£${p.monthly_dues || 0} Sterlin</strong>
        </div>
        <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl">
          <span class="block text-slate-400 text-[10px] font-bold uppercase">Eşya Durumu</span>
          <strong class="${isFurnished ? 'text-blue-700' : 'text-slate-600'} font-bold">${isFurnished ? 'Full Eşyalı' : 'Eşyasız'}</strong>
        </div>
        <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl">
          <span class="block text-slate-400 text-[10px] font-bold uppercase">Bulunduğu Kat</span>
          <strong class="text-slate-800 font-bold">${p.floor_number || 1}. Kat</strong>
        </div>
        <div class="p-3 bg-slate-50 border border-slate-100 rounded-xl">
          <span class="block text-slate-400 text-[10px] font-bold uppercase">Klima Tipi</span>
          <strong class="${isInverter ? 'text-emerald-700' : 'text-slate-700'} font-bold">${isInverter ? 'İnverter (A++)' : 'Standart'}</strong>
        </div>
      `;

      lucide.createIcons();
    }
  } catch (err) {
    document.getElementById('detailTitle').innerText = "Hata";
    document.getElementById('detailDescription').innerText = "Veri alınamadı: " + err.message;
  }
}

function setupDetailModalEvents() {
  const closeBtn1 = document.getElementById('closeDetailModalBtn');
  const closeBtn2 = document.getElementById('closeDetailModalBtn2');
  const triggerAiBtn = document.getElementById('detailTriggerAiBtn');
  const viewOwnerBtn = document.getElementById('viewOwnerProfileBtn');
  const messageOwnerBtn = document.getElementById('messageOwnerBtn');

  if (closeBtn1) closeBtn1.onclick = () => hideModal('propertyDetailModal');
  if (closeBtn2) closeBtn2.onclick = () => hideModal('propertyDetailModal');

  if (triggerAiBtn) {
    triggerAiBtn.onclick = () => {
      hideModal('propertyDetailModal');
      if (currentDetailPropertyId) runSinglePropertyAnalysis(currentDetailPropertyId);
    };
  }

  if (viewOwnerBtn) {
    viewOwnerBtn.onclick = () => {
      if (currentDetailOwnerId) openUserProfileModal(currentDetailOwnerId);
    };
  }

  if (messageOwnerBtn) {
    messageOwnerBtn.onclick = () => {
      if (!currentUser) {
        notify.warning("Oturum Gerekli", "Mesaj gönderebilmek için lütfen önce giriş yapın!");
        showModal('authModal');
        return;
      }
      if (currentUser.id === currentDetailOwnerId) {
        notify.info("Kendi İlanınız", "Kendi ilanınıza mesaj gönderemezsiniz.");
        return;
      }
      notify.success("Sohbet Başlatılıyor", `${document.getElementById('detailOwnerName').innerText} ile sohbet açılıyor...`);
    };
  }
}

// 8. Kullanıcı Profili ve "İlanlarım" Konsolu
async function openUserProfileModal(userId) {
  showModal('userProfileModal');

  document.getElementById('profileViewCard').style.display = 'block';
  document.getElementById('profileEditFormContainer').style.display = 'none';

  document.getElementById('profileModalName').innerText = "Yükleniyor...";
  document.getElementById('profileModalBio').innerText = "Bilgiler getiriliyor...";
  document.getElementById('profileListingsContainer').innerHTML = '';

  try {
    const res = await fetch(`../backend/api/user_profile.php?id=${userId}`);
    const rawText = await res.text();
    if (!rawText) throw new Error("Sunucudan boş yanıt döndü.");
    const data = JSON.parse(rawText);

    if (data.status === 'success' && data.user) {
      const u = data.user;
      const listings = data.listings || [];
      viewedProfileData = u;

      document.getElementById('profileModalName').innerText = u.full_name || 'İsimsiz Kullanıcı';
      document.getElementById('profileModalCity').innerHTML = `<i data-lucide="map-pin" class="w-3.5 h-3.5 text-blue-600"></i> <span>${u.city_region || 'Gazimağusa / KKTC'}</span>`;
      document.getElementById('profileModalBio').innerText = u.bio || "Henüz bir biyografi eklenmemiş.";
      document.getElementById('profileModalAvatar').src = u.avatar_url || 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=200&q=80';
      document.getElementById('profileModalMemberSince').innerText = u.member_since_formatted || 'Ekim 2026';

      const roleMap = { student: '🎓 Öğrenci', landlord: '🏠 Ev Sahibi', agent: '🏢 Emlak Ofisi', admin: '🛡️ Yönetici', superadmin: '⭐ Sistem Yöneticisi' };
      document.getElementById('profileModalRoleBadge').innerText = roleMap[u.role] || u.role;

      const emailRow = document.getElementById('profileEmailRow');
      if (u.email) {
        emailRow.style.display = 'flex';
        document.getElementById('profileModalEmail').innerText = u.email;
      } else {
        emailRow.style.display = 'none';
      }

      const isMyOwnProfile = (currentUser && currentUser.id === u.id);
      const msgBtn = document.getElementById('profileDirectMessageBtn');
      const waBtn = document.getElementById('profileWhatsappLink');
      const editToggleBtn = document.getElementById('profileEditToggleBtn');

      if (isMyOwnProfile) {
        msgBtn.style.display = 'none';
        waBtn.style.display = 'none';
        editToggleBtn.style.display = 'inline-block';
      } else {
        editToggleBtn.style.display = 'none';
        msgBtn.style.display = 'block';

        if (u.phone) {
          waBtn.style.display = 'block';
          const cleanWa = u.phone.replace(/[^0-9]/g, '');
          waBtn.href = `https://wa.me/${cleanWa}?text=Merhaba%20${encodeURIComponent(u.full_name)},%20IslandProp'taki%20ilanınızla%20ilgileniyorum.`;
        } else {
          waBtn.style.display = 'none';
        }
      }

      document.getElementById('profileListingsCount').innerText = listings.length;
      const listContainer = document.getElementById('profileListingsContainer');
      listContainer.innerHTML = '';

      if (listings.length === 0) {
        listContainer.innerHTML = `<div class="p-6 bg-slate-50 border border-slate-100 rounded-2xl text-center text-xs text-slate-400">Henüz yayınlanmış bir ilan bulunmuyor.</div>`;
      } else {
        listings.forEach(item => {
          const card = document.createElement('div');
          card.className = "flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-3.5 bg-slate-50 hover:bg-slate-100/90 rounded-2xl border border-slate-200 transition";

          let statusBadge = '';
          if (item.status === 'pending') {
            statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-200">🟡 Admin Onayı Bekliyor</span>`;
          } else if (item.status === 'active') {
            statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">🟢 Yayında (Aktif)</span>`;
          } else if (item.status === 'rented') {
            statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-purple-100 text-purple-800 border border-purple-200">🟣 Kiralandı (Gizli)</span>`;
          } else if (item.status === 'rejected') {
            statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-red-100 text-red-800 border border-red-200">🔴 Reddedildi</span>`;
          } else {
            statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-200 text-slate-700">${item.status}</span>`;
          }

          card.innerHTML = `
            <div class="flex items-center gap-3 min-w-0">
              <img src="${resolveImageUrl(item.cover_image)}" class="w-16 h-16 rounded-xl object-cover shrink-0 border border-slate-200">
              <div class="min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  ${statusBadge}
                  <span class="text-[10px] text-slate-400 font-semibold flex items-center gap-1">
                    <i data-lucide="calendar" class="w-3 h-3"></i> ${item.created_date || 'Bugün'}
                  </span>
                </div>
                <h5 class="text-xs font-bold text-slate-900 truncate">${item.title}</h5>
                <p class="text-[11px] text-slate-500">${item.district_name || 'Gazimağusa'} • ${item.bedrooms}+1 • <strong class="text-blue-600 font-extrabold">£${Math.round(item.price)}/ay</strong></p>
              </div>
            </div>

            ${isMyOwnProfile ? `
              <div class="flex items-center gap-1.5 shrink-0 self-end sm:self-center">
                ${item.status === 'active' ? `
                  <button onclick="toggleRentalStatus(${item.id}, 'rented')" class="px-2.5 py-1.5 rounded-xl bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 text-[11px] font-bold transition flex items-center gap-1 shadow-2xs">
                    <i data-lucide="check-circle" class="w-3.5 h-3.5"></i>
                    <span>Kiralandı Yap</span>
                  </button>
                ` : (item.status === 'rented' ? `
                  <button onclick="toggleRentalStatus(${item.id}, 'active')" class="px-2.5 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-[11px] font-bold transition flex items-center gap-1 shadow-2xs">
                    <i data-lucide="play" class="w-3.5 h-3.5"></i>
                    <span>Tekrar Yayına Al</span>
                  </button>
                ` : '')}

                <button onclick="openEditPropertyModal(${item.id})" class="px-2.5 py-1.5 rounded-xl bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 text-[11px] font-bold transition flex items-center gap-1 shadow-2xs">
                  <i data-lucide="edit-3" class="w-3.5 h-3.5"></i>
                  <span>Düzenle</span>
                </button>
              </div>
            ` : `
              <button onclick="openPropertyDetailModal(${item.id})" class="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-700 text-xs font-bold hover:bg-blue-100 transition">İncele</button>
            `}
          `;
          listContainer.appendChild(card);
        });
      }

      lucide.createIcons();
    } else {
      notify.error("Hata", data.message || "Profil verisi yüklenemedi.");
      document.getElementById('profileModalName').innerText = "Hata Oluştu";
      document.getElementById('profileModalBio').innerText = data.message || "Profil bilgileri getirilemedi.";
    }
  } catch (err) {
    notify.error("Bağlantı Hatası", err.message);
    document.getElementById('profileModalName').innerText = "Hata Oluştu";
    document.getElementById('profileModalBio').innerText = "Sunucu yanıtı okunamadı: " + err.message;
  }
}

function setupUserProfileModal() {
  const closeBtn = document.getElementById('closeProfileModalBtn');
  const editToggleBtn = document.getElementById('profileEditToggleBtn');
  const cancelEditBtn = document.getElementById('cancelEditProfileBtn');
  const editForm = document.getElementById('editProfileForm');

  const profileCard = document.getElementById('profileViewCard');
  const editContainer = document.getElementById('profileEditFormContainer');

  if (closeBtn) closeBtn.onclick = () => hideModal('userProfileModal');

  if (editToggleBtn) {
    editToggleBtn.onclick = () => {
      if (!viewedProfileData) return;
      document.getElementById('editFullName').value = viewedProfileData.full_name || '';
      document.getElementById('editCityRegion').value = viewedProfileData.city_region || '';
      document.getElementById('editBio').value = viewedProfileData.bio || '';
      document.getElementById('editShowEmail').checked = (parseInt(viewedProfileData.show_email) === 1);
      document.getElementById('editShowPhone').checked = (parseInt(viewedProfileData.show_phone) === 1);

      document.getElementById('editOldPassword').value = '';
      document.getElementById('editNewPassword').value = '';
      document.getElementById('editAvatarFile').value = '';

      profileCard.style.display = 'none';
      editContainer.style.display = 'block';
    };
  }

  if (cancelEditBtn) {
    cancelEditBtn.onclick = () => {
      editContainer.style.display = 'none';
      profileCard.style.display = 'block';
    };
  }

  if (editForm) {
    editForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      if (!currentUser || !currentUser.id) {
        notify.error("Oturum Yok", "Lütfen tekrar giriş yapın.");
        return;
      }

      const formData = new FormData();
      formData.append('user_id', currentUser.id);
      formData.append('full_name', document.getElementById('editFullName').value.trim());
      formData.append('city_region', document.getElementById('editCityRegion').value.trim());
      formData.append('bio', document.getElementById('editBio').value.trim());
      formData.append('show_email', document.getElementById('editShowEmail').checked ? '1' : '0');
      formData.append('show_phone', document.getElementById('editShowPhone').checked ? '1' : '0');

      const avatarInput = document.getElementById('editAvatarFile');
      if (avatarInput && avatarInput.files[0]) {
        formData.append('avatar_file', avatarInput.files[0]);
      }

      const oldPass = document.getElementById('editOldPassword').value;
      const newPass = document.getElementById('editNewPassword').value;
      if (newPass) {
        if (!oldPass) {
          notify.warning("Eksik Bilgi", "Şifrenizi değiştirmek için lütfen mevcut şifrenizi girin!");
          return;
        }
        formData.append('old_password', oldPass);
        formData.append('new_password', newPass);
      }

      const btn = document.getElementById('saveProfileBtn');
      btn.disabled = true;
      btn.innerText = "Kaydediliyor...";

      try {
        const res = await fetch('../backend/api/auth/update_profile.php', { method: 'POST', body: formData });
        const rawText = await res.text();
        if (!rawText) throw new Error("Sunucu yanıt vermedi.");
        const data = JSON.parse(rawText);

        if (data.status === 'success') {
          currentUser = data.user;
          localStorage.setItem('islandprop_user', JSON.stringify(currentUser));
          renderAuthNavbar(currentUser);
          notify.success("Profil Güncellendi", "Bilgileriniz kaydedildi.");
          openUserProfileModal(currentUser.id);
        } else {
          notify.error("Hata", data.message || 'Profil güncellenemedi.');
        }
      } catch (err) {
        notify.error("Bağlantı Hatası", err.message);
      } finally {
        btn.disabled = false;
        btn.innerText = "Değişiklikleri Kaydet";
      }
    });
  }
}

// 9. İlan Düzenleme ve Kiralandı Aksiyonları
window.toggleRentalStatus = async function(propId, newStatus) {
  const confirmText = (newStatus === 'rented') 
    ? "Bu ilanı 'Kiralandı' olarak işaretlemek üzeresiniz. İlan haritadan gizlenecektir." 
    : "İlanınızı tekrar aktif ederek haritada yayına almak üzeresiniz.";
  
  const ok = await notify.confirm("Durum Güncellemesi", confirmText, "Evet, Değiştir");
  if (!ok) return;

  try {
    const res = await fetch('../backend/api/manage_property.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'toggle_rental_status', user_id: currentUser.id, property_id: propId, status: newStatus })
    });
    const rawText = await res.text();
    if (!rawText) throw new Error("Boş yanıt döndü.");
    const d = JSON.parse(rawText);

    if (d.status === 'success') {
      notify.success("Güncellendi", d.message);
      openUserProfileModal(currentUser.id);
      fetchProperties();
    } else {
      notify.error("Hata", d.message);
    }
  } catch (err) {
    notify.error("Hata", err.message);
  }
};

window.openEditPropertyModal = async function(propId) {
  try {
    const res = await fetch(`../backend/api/manage_property.php?id=${propId}&user_id=${currentUser.id}`);
    const rawText = await res.text();
    if (!rawText) throw new Error("Sunucudan yanıt alınamadı.");
    const data = JSON.parse(rawText);

    if (data.status === 'success' && data.property) {
      const p = data.property;
      document.getElementById('editPropId').value = p.id;
      document.getElementById('editPropTitle').value = p.title || '';
      document.getElementById('editPropPrice').value = p.price || 0;
      document.getElementById('editPropDeposit').value = p.deposit_amount || 0;
      document.getElementById('editPropDues').value = p.monthly_dues || 0;
      document.getElementById('editPropBedrooms').value = p.bedrooms || 2;
      document.getElementById('editPropArea').value = p.area_sqm || 60;
      document.getElementById('editPropDescription').value = p.description || '';
      document.getElementById('editPropDateTag').innerText = `İlan Tarihi: ${p.created_at_formatted || 'Bilinmiyor'}`;

      showModal('editPropertyModal');
    } else {
      notify.error("Hata", data.message || "İlan bilgileri getirilemedi.");
    }
  } catch (err) {
    notify.error("Bağlantı Hatası", err.message);
  }
};

function setupPropertyEditModule() {
  const modal = document.getElementById('editPropertyModal');
  const closeBtn = document.getElementById('closeEditPropModalBtn');
  const cancelBtn = document.getElementById('cancelEditPropBtn');
  const form = document.getElementById('editPropertyForm');

  if (closeBtn) closeBtn.onclick = () => hideModal('editPropertyModal');
  if (cancelBtn) cancelBtn.onclick = () => hideModal('editPropertyModal');

  if (form) {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const payload = {
        action: 'update_property_details',
        user_id: currentUser.id,
        property_id: parseInt(document.getElementById('editPropId').value),
        title: document.getElementById('editPropTitle').value.trim(),
        price: parseFloat(document.getElementById('editPropPrice').value),
        deposit_amount: parseFloat(document.getElementById('editPropDeposit').value) || 0,
        monthly_dues: parseFloat(document.getElementById('editPropDues').value) || 0,
        bedrooms: parseInt(document.getElementById('editPropBedrooms').value),
        area_sqm: parseInt(document.getElementById('editPropArea').value) || 60,
        description: document.getElementById('editPropDescription').value.trim()
      };

      const btn = document.getElementById('saveEditPropBtn');
      btn.disabled = true;
      btn.innerText = "Kaydediliyor...";

      try {
        const res = await fetch('../backend/api/manage_property.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const rawText = await res.text();
        if (!rawText) throw new Error("Sunucudan boş yanıt döndü.");
        const d = JSON.parse(rawText);

        if (d.status === 'success') {
          notify.success("İlan Güncellendi", d.message);
          hideModal('editPropertyModal');
          openUserProfileModal(currentUser.id);
          fetchProperties();
        } else {
          notify.error("Hata", d.message);
        }
      } catch (err) {
        notify.error("Hata", err.message);
      } finally {
        btn.disabled = false;
        btn.innerText = "Değişiklikleri Kaydet";
      }
    });
  }
}

// 10. AI Analiz Motoru (Tekil ve Çoklu Analiz)
async function runSinglePropertyAnalysis(propertyId) {
  const loading = document.getElementById('aiLoading');
  const content = document.getElementById('aiContent');
  const modalTitle = document.getElementById('aiModalTitle');
  const modalSub = document.getElementById('aiModalSub');

  modalTitle.innerText = "IslandProp AI Çevre & Emsal Analizi";
  modalSub.innerText = "Seçili evin çevresi taranıyor ve analiz yapılıyor...";

  showModal('aiModal');
  loading.style.display = 'block';
  content.innerHTML = '';

  try {
    const res = await fetch(`../backend/api/property_insight.php?id=${propertyId}&campus_id=${currentCampusId}`);
    const rawText = await res.text();
    if (!rawText) throw new Error("Sunucudan boş yanıt döndü.");
    const data = JSON.parse(rawText);
    loading.style.display = 'none';

    if (data.status === 'success') {
      let formattedText = data.report
        .replace(/#+ (.*?)\n/g, '<h3 class="text-sm font-extrabold text-slate-900 mt-3 mb-1.5 flex items-center gap-1.5">$1</h3>')
        .replace(/#+(.*?):/g, '<h3 class="text-sm font-extrabold text-slate-900 mt-3 mb-1.5 flex items-center gap-1.5">$1:</h3>')
        .replace(/\*\*(.*?)\*\*/g, '<strong class="font-bold text-slate-900">$1</strong>')
        .replace(/\*(.*?)\*/g, '<span class="text-slate-800 font-medium">$1</span>')
        .replace(/^\* (.*?)$/gm, '<li class="ml-4 list-disc text-slate-600 my-1">$1</li>')
        .replace(/^- (.*?)$/gm, '<li class="ml-4 list-disc text-slate-600 my-1">$1</li>')
        .replace(/• (.*?)\n/g, '<div class="py-0.5">• $1</div>')
        .replace(/---\n/g, '<hr class="my-3 border-slate-200">')
        .replace(/\n\n/g, '<div class="h-2"></div>');

      content.innerHTML = `
        <div class="prose prose-sm max-w-none text-xs leading-relaxed text-slate-700 pb-4">${formattedText}</div>
        <div class="pt-3 border-t border-slate-200 flex items-center justify-between text-[11px] text-slate-400">
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>${data.engine || 'IslandProp Spatial Zekası'}</span>
          <span>DAÜ Spatial Analitiği</span>
        </div>
      `;
    } else {
      content.innerHTML = `<div class="p-4 bg-red-50 text-red-700 rounded-xl text-xs font-semibold">${data.message || 'Analiz yapılamadı.'}</div>`;
      notify.error("AI Hatası", data.message || 'Analiz yapılamadı.');
    }
  } catch (err) {
    loading.style.display = 'none';
    content.innerHTML = `<div class="p-4 bg-red-50 text-red-700 rounded-xl text-xs font-semibold">İstek Hatası: ${err.message}</div>`;
    notify.error("Bağlantı Hatası", err.message);
  }
}

async function runAiComparison() {
  if (selectedProperties.size === 0) {
    notify.warning("Seçim Yapılmadı", "Lütfen karşılaştırmak için en az 1 ilan seçin.");
    return;
  }

  const loading = document.getElementById('aiLoading');
  const content = document.getElementById('aiContent');
  const modalTitle = document.getElementById('aiModalTitle');
  const modalSub = document.getElementById('aiModalSub');

  modalTitle.innerText = "IslandProp AI Kıyaslama Raporu";
  modalSub.innerText = `${selectedProperties.size} ilan karşılaştırılıyor...`;

  showModal('aiModal');
  loading.style.display = 'block';
  content.innerHTML = '';

  const walkVal = document.getElementById('filterWalk').value;
  const hasCar = (walkVal === "");

  try {
    const res = await fetch('../backend/api/ai_advisor.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ property_ids: Array.from(selectedProperties), campus_id: currentCampusId, has_car: hasCar })
    });
    const rawText = await res.text();
    if (!rawText) throw new Error("Sunucudan boş yanıt döndü.");
    const data = JSON.parse(rawText);
    loading.style.display = 'none';

    if (data.status === 'success') {
      let formattedText = data.report
        .replace(/#+ (.*?)\n/g, '<h3 class="text-sm font-bold text-slate-900 mt-3 mb-1">$1</h3>')
        .replace(/\*\*(.*?)\*\*/g, '<strong class="font-bold text-slate-900">$1</strong>')
        .replace(/\*(.*?)\*/g, '<em class="text-slate-600">$1</em>')
        .replace(/---\n/g, '<hr class="my-3 border-slate-200">')
        .replace(/\n/g, '<br>');

      content.innerHTML = `
        <div class="space-y-2 text-xs leading-relaxed text-slate-700">${formattedText}</div>
        <div class="pt-3 border-t border-slate-200 flex items-center justify-between text-[11px] text-slate-400 mt-3">
          <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>${data.engine || 'IslandProp Spatial Zekası'}</span>
          <span>DAÜ TrueCost™ Kıyaslama</span>
        </div>
      `;
    } else {
      content.innerHTML = `<div class="p-4 bg-red-50 text-red-700 rounded-xl text-xs font-semibold">${data.message || 'Kıyaslama yapılamadı.'}</div>`;
      notify.error("Hata", data.message || 'Kıyaslama yapılamadı.');
    }
  } catch (err) {
    loading.style.display = 'none';
    content.innerHTML = `<div class="p-4 bg-red-50 text-red-700 rounded-xl text-xs font-semibold">Bağlantı hatası oluştu: ${err.message}</div>`;
    notify.error("Hata", "Karşılaştırma servisine bağlanılamadı.");
  }
}

// 11. Filtreler & NLP Arama
function setupFilterEvents() {
  document.getElementById('campusSelect').addEventListener('change', (e) => {
    currentCampusId = parseInt(e.target.value);
    fetchProperties();
  });

  ['filterPrice', 'filterBedrooms', 'filterWalk'].forEach(elemId => {
    const el = document.getElementById(elemId);
    if (!el) return;
    el.addEventListener('input', () => {
      clearTimeout(window.filterTimer);
      window.filterTimer = setTimeout(fetchProperties, 300);
    });
    el.addEventListener('change', fetchProperties);
  });

  document.getElementById('openAiModalBtn').onclick = runAiComparison;
  document.getElementById('closeAiModalBtn').onclick = () => hideModal('aiModal');
}

function setupNlpSearch() {
  const input = document.getElementById('nlpSearchInput');
  const btn = document.getElementById('nlpSearchBtn');
  const clearBtn = document.getElementById('clearNlpBtn');
  if (!input || !btn) return;

  const executeSearch = async () => {
    const text = input.value.trim();
    if (!text) return;

    btn.disabled = true;
    btn.innerHTML = `<div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>`;

    try {
      const res = await fetch('../backend/api/nlp_search.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query: text })
      });
      const data = await res.json();

      if (data.status === 'success') {
        const p = data.parsed;
        if (p.campus_id) {
          currentCampusId = p.campus_id;
          document.getElementById('campusSelect').value = p.campus_id;
        }
        if (p.max_price) document.getElementById('filterPrice').value = p.max_price;
        if (p.bedrooms) document.getElementById('filterBedrooms').value = p.bedrooms;
        if (p.max_walk !== undefined) {
          document.getElementById('filterWalk').value = p.max_walk === "" ? "" : (p.max_walk <= 10 ? "10" : (p.max_walk <= 15 ? "15" : "20"));
        }
        renderNlpTags(p.tags_detected);
        fetchProperties();
        notify.success("Arama Uygulandı", `${p.tags_detected ? p.tags_detected.length : 0} adet kriter tespit edildi.`);
      }
    } catch (err) {
      console.error("NLP Hatası:", err);
      notify.error("Arama Hatası", "Doğal dil sorgusu işlenemedi.");
    } finally {
      btn.disabled = false;
      btn.innerHTML = `<span>Ara</span><i data-lucide="arrow-right" class="w-3 h-3"></i>`;
      lucide.createIcons();
    }
  };

  btn.addEventListener('click', executeSearch);
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') executeSearch(); });

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      input.value = '';
      document.getElementById('filterPrice').value = '';
      document.getElementById('filterBedrooms').value = '';
      document.getElementById('filterWalk').value = '';
      document.getElementById('nlpTagsContainer').style.display = 'none';
      fetchProperties();
      notify.info("Filtreler Temizlendi");
    });
  }
}

function renderNlpTags(tags) {
  const container = document.getElementById('nlpTagsContainer');
  const list = document.getElementById('nlpTagsList');
  if (!container || !list) return;
  list.innerHTML = '';
  if (!tags || tags.length === 0) {
    container.style.display = 'none';
    return;
  }
  tags.forEach(tag => {
    const badge = document.createElement('span');
    badge.className = 'bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded-full flex items-center gap-1';
    badge.innerText = tag;
    list.appendChild(badge);
  });
  container.style.display = 'flex';
}

// 12. Yeni İlan Ekleme (Onay Havuzuna Gönderme)
function setupNewPropertyModule() {
  const openBtn = document.getElementById('openNewPropertyBtn');
  const closeBtn = document.getElementById('closeNewPropertyModalBtn');
  const pickBtn = document.getElementById('pickLocationBtn');
  const form = document.getElementById('newPropertyForm');
  const alertBox = document.getElementById('mapPickingAlert');

  const coverInput = document.getElementById('propCoverFile');
  const coverPreviewContainer = document.getElementById('coverPreviewContainer');
  const coverPreviewImg = document.getElementById('coverPreviewImg');
  const galleryInput = document.getElementById('propGalleryFiles');
  const galleryLabel = document.getElementById('galleryCountLabel');

  if (!openBtn) return;

  openBtn.onclick = () => {
    if (!currentUser || !currentUser.id) {
      notify.warning("Giriş Gerekli", "İlan ekleyebilmek için lütfen önce giriş yapın!");
      showModal('authModal');
      return;
    }
    showModal('newPropertyModal');
  };

  if (coverInput) {
    coverInput.addEventListener('change', () => {
      const file = coverInput.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = (e) => {
          coverPreviewImg.src = e.target.result;
          coverPreviewContainer.style.display = 'block';
        };
        reader.readAsDataURL(file);
      } else {
        coverPreviewContainer.style.display = 'none';
      }
    });
  }

  if (galleryInput) {
    galleryInput.addEventListener('change', () => {
      const count = galleryInput.files.length;
      if (count > 0) {
        galleryLabel.innerText = `${count} adet ekstra galeri fotoğrafı seçildi.`;
        galleryLabel.className = "block text-[10px] text-blue-600 font-bold";
      } else {
        galleryLabel.innerText = "Tüm odaları seçebilirsiniz.";
        galleryLabel.className = "block text-[10px] text-slate-400 font-semibold";
      }
    });
  }

  closeBtn.onclick = () => {
    hideModal('newPropertyModal');
    alertBox.style.display = 'none';
    isPickingLocation = false;
  };

  pickBtn.onclick = () => {
    hideModal('newPropertyModal');
    alertBox.style.display = 'flex';
    isPickingLocation = true;
    notify.info("Konum Seçimi", "Haritada evin yerini tıklayarak seçin.");
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    if (!currentUser || !currentUser.id) {
      notify.error("Oturum Yok", "İlan eklemek için lütfen giriş yapın.");
      showModal('authModal');
      return;
    }

    if (!pickedLocation) {
      notify.warning("Konum Eksik", "Lütfen 'Haritadan Seç' butonuna basarak haritada evi işaretleyin!");
      return;
    }

    const formData = new FormData();
    formData.append('user_id', currentUser.id);
    formData.append('title', document.getElementById('propTitle').value.trim());
    formData.append('price', document.getElementById('propPrice').value);
    formData.append('deposit_amount', document.getElementById('propDeposit').value || (parseFloat(document.getElementById('propPrice').value) * 2));
    formData.append('monthly_dues', document.getElementById('propDues').value || '0');
    formData.append('payment_period', document.getElementById('propPaymentPeriod').value);
    formData.append('district_id', document.getElementById('propDistrict').value);
    formData.append('bedrooms', document.getElementById('propBedrooms').value);
    formData.append('area_sqm', document.getElementById('propArea').value);
    formData.append('floor_number', document.getElementById('propFloor').value);
    formData.append('is_furnished', document.getElementById('propFurnished').value);
    formData.append('has_inverter_ac', document.getElementById('propInverter').checked ? '1' : '0');
    formData.append('has_generator', document.getElementById('propGenerator').checked ? '1' : '0');
    formData.append('has_water_tank', document.getElementById('propWaterTank').checked ? '1' : '0');
    formData.append('description', document.getElementById('propDescription').value.trim());
    formData.append('lat', pickedLocation.lat);
    formData.append('lng', pickedLocation.lng);

    if (coverInput.files[0]) formData.append('cover_image', coverInput.files[0]);

    if (galleryInput.files.length > 0) {
      for (let i = 0; i < galleryInput.files.length; i++) {
        formData.append('gallery_images[]', galleryInput.files[i]);
      }
    }

    const submitBtn = document.getElementById('savePropertyBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = `<span>İşleniyor...</span>`;

    try {
      const res = await fetch('../backend/api/create_property.php', { method: 'POST', body: formData });
      const rawText = await res.text();
      if (!rawText) throw new Error("Sunucudan boş yanıt döndü.");
      const resData = JSON.parse(rawText);

      if (resData.status === 'success') {
        notify.success("İlan Alındı", resData.message);
        hideModal('newPropertyModal');
        form.reset();
        coverPreviewContainer.style.display = 'none';
        pickedLocation = null;
        if (tempPickMarker) map.removeLayer(tempPickMarker);
        document.getElementById('selectedCoordsText').innerText = "Henüz nokta seçilmedi";

        fetchProperties();
        openUserProfileModal(currentUser.id);
        checkAdminPendingCount();
      } else {
        notify.error("Kayıt Başarısız", resData.message || 'İlan kaydedilemedi.');
      }
    } catch (err) {
      notify.error("Sunucu Hatası", err.message);
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = `<span>İlanı Onaya Gönder</span>`;
    }
  });
}

// 13. Giriş / Kayıt / OTP Modülü
function setupAuthModule() {
  const openBtn = document.getElementById('openAuthModalBtn');
  const closeBtn = document.getElementById('closeAuthModalBtn');
  const logoutBtn = document.getElementById('logoutBtn');

  const tabLogin = document.getElementById('tabLoginBtn');
  const tabRegister = document.getElementById('tabRegisterBtn');
  const loginForm = document.getElementById('loginForm');
  const regForm = document.getElementById('registerForm');
  const otpSection = document.getElementById('otpSection');
  const otpForm = document.getElementById('otpForm');

  if (openBtn) openBtn.onclick = () => showModal('authModal');
  if (closeBtn) closeBtn.onclick = () => hideModal('authModal');

  if (logoutBtn) {
    logoutBtn.onclick = async () => {
      const ok = await notify.confirm("Çıkış Yapılsın mı?", "Hesabınızdan güvenli çıkış yapmak üzeresiniz.", "Evet, Çıkış Yap");
      if (ok) {
        localStorage.removeItem('islandprop_user');
        currentUser = null;
        renderAuthNavbar(null);
        notify.info("Çıkış Yapıldı", "Oturumunuz kapatıldı.");
      }
    };
  }

  tabLogin.onclick = () => {
    tabLogin.className = "flex-1 py-1.5 text-xs font-bold rounded-lg transition bg-white text-slate-900 shadow-sm";
    tabRegister.className = "flex-1 py-1.5 text-xs font-bold rounded-lg transition text-slate-500 hover:text-slate-900";
    loginForm.style.display = 'block';
    regForm.style.display = 'none';
    otpSection.style.display = 'none';
    document.getElementById('authTabs').style.display = 'flex';
  };

  tabRegister.onclick = () => {
    tabRegister.className = "flex-1 py-1.5 text-xs font-bold rounded-lg transition bg-white text-slate-900 shadow-sm";
    tabLogin.className = "flex-1 py-1.5 text-xs font-bold rounded-lg transition text-slate-500 hover:text-slate-900";
    regForm.style.display = 'block';
    loginForm.style.display = 'none';
    otpSection.style.display = 'none';
    document.getElementById('authTabs').style.display = 'flex';
  };

  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const identityVal = document.getElementById('loginIdentity').value.trim();
    const passwordVal = document.getElementById('loginPassword').value;

    if (!identityVal || !passwordVal) {
      notify.warning("Eksik Bilgi", "Lütfen bilgilerinizi girin.");
      return;
    }

    const btn = document.getElementById('loginSubmitBtn');
    btn.disabled = true;
    btn.innerText = "Doğrulanıyor...";

    try {
      const res = await fetch('../backend/api/auth/login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ identity: identityVal, password: passwordVal })
      });
      const data = await res.json();

      if (data.status === 'success') {
        currentUser = data.user;
        localStorage.setItem('islandprop_user', JSON.stringify(currentUser));
        renderAuthNavbar(currentUser);
        hideModal('authModal');
        loginForm.reset();
        notify.success("Giriş Başarılı!", `Hoş geldiniz, ${currentUser.full_name}`);
        if (['admin', 'superadmin'].includes(currentUser.role)) {
          checkAdminPendingCount();
        }
      } else {
        notify.error("Giriş Başarısız", data.message || 'Kimlik doğrulanamadı.');
      }
    } catch (err) {
      notify.error("Sunucu Hatası", err.message);
    } finally {
      btn.disabled = false;
      btn.innerText = "Güvenli Giriş Yap";
    }
  });

  regForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const pass = document.getElementById('regPassword').value;
    const passConfirm = document.getElementById('regPasswordConfirm').value;

    if (pass !== passConfirm) {
      notify.error("Şifreler Eşleşmiyor", "İki şifre de aynı olmalıdır.");
      return;
    }

    const btn = document.getElementById('regSubmitBtn');
    btn.disabled = true;
    btn.innerText = "Kayıt Yapılıyor...";

    const payload = {
      full_name: document.getElementById('regFullName').value.trim(),
      email: document.getElementById('regEmail').value.trim(),
      phone: document.getElementById('regPhone').value.trim(),
      city_region: document.getElementById('regCity').value.trim(),
      role: document.getElementById('regRole').value,
      password: pass,
      password_confirm: passConfirm
    };

    try {
      const res = await fetch('../backend/api/auth/register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();

      if (data.status === 'success') {
        pendingOtpUserId = data.user_id;
        document.getElementById('otpPhoneText').innerText = data.phone;
        document.getElementById('demoOtpCode').innerText = data.demo_otp || '123456';

        regForm.style.display = 'none';
        document.getElementById('authTabs').style.display = 'none';
        otpSection.style.display = 'block';
        notify.info("Doğrulama Kodu Gönderildi", "Lütfen WhatsApp kodunu girin.");
      } else {
        notify.error("Kayıt Hatası", data.message);
      }
    } catch (err) {
      notify.error("Bağlantı Hatası", err.message);
    } finally {
      btn.disabled = false;
      btn.innerText = "Hesap Oluştur & WhatsApp Kodu İste";
    }
  });

  const otpInput = document.getElementById('otpInput');
  if (otpInput) {
    otpInput.addEventListener('input', (e) => {
      e.target.value = e.target.value.replace(/[^0-9]/g, '').slice(0, 6);
    });
  }

  otpForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = document.getElementById('otpInput').value.trim();

    if (code.length !== 6) {
      notify.warning("Eksik Kod", "Lütfen 6 haneli kodu eksiksiz girin.");
      return;
    }

    const btn = document.getElementById('verifyOtpBtn');
    btn.disabled = true;
    btn.innerText = "Onaylanıyor...";

    try {
      const res = await fetch('../backend/api/auth/verify_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: pendingOtpUserId, otp_code: code })
      });
      const data = await res.json();

      if (data.status === 'success') {
        currentUser = data.user;
        localStorage.setItem('islandprop_user', JSON.stringify(currentUser));
        renderAuthNavbar(currentUser);
        hideModal('authModal');
        otpForm.reset();
        regForm.reset();
        notify.success("Telefon Doğrulandı!", `Hoş geldiniz, ${currentUser.full_name}`);
      } else {
        notify.error("Hatalı Kod", data.message);
      }
    } catch (err) {
      notify.error("Hata", err.message);
    } finally {
      btn.disabled = false;
      btn.innerText = "Numarayı Doğrula ve Başla";
    }
  });
}

// 14. Kapsamlı Admin Modülü (Onay / Red / Bildirim Havuzu)
function setupAdminModule() {
  const openBtn = document.getElementById('adminPanelOpenBtn');
  const closeBtn = document.getElementById('closeAdminModalBtn');

  const tabProps = document.getElementById('tabAdminProps');
  const tabUsers = document.getElementById('tabAdminUsers');
  const tabLogs  = document.getElementById('tabAdminLogs');

  const secProps = document.getElementById('adminPropsSection');
  const secUsers = document.getElementById('adminUsersSection');
  const secLogs  = document.getElementById('adminLogsSection');

  const clearLogsBtn = document.getElementById('clearLogsBtn');

  if (openBtn) {
    openBtn.onclick = () => {
      showModal('adminModal');
      loadAdminDashboard();
    };
  }

  if (closeBtn) closeBtn.onclick = () => hideModal('adminModal');

  tabProps.onclick = () => {
    tabProps.className = "px-4 py-2 text-xs font-bold border-b-2 border-purple-600 text-purple-700 flex items-center gap-1.5 transition";
    tabUsers.className = "px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition";
    tabLogs.className  = "px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition";
    secProps.style.display = 'block';
    secUsers.style.display = 'none';
    secLogs.style.display  = 'none';
  };

  tabUsers.onclick = () => {
    tabUsers.className = "px-4 py-2 text-xs font-bold border-b-2 border-purple-600 text-purple-700 flex items-center gap-1.5 transition";
    tabProps.className = "px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition";
    tabLogs.className  = "px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition";
    secUsers.style.display = 'block';
    secProps.style.display = 'none';
    secLogs.style.display  = 'none';
  };

  tabLogs.onclick = () => {
    tabLogs.className  = "px-4 py-2 text-xs font-bold border-b-2 border-purple-600 text-purple-700 flex items-center gap-1.5 transition";
    tabProps.className = "px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition";
    tabUsers.className = "px-4 py-2 text-xs font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-800 flex items-center gap-1.5 transition";
    secLogs.style.display  = 'block';
    secProps.style.display = 'none';
    secUsers.style.display = 'none';
  };

  if (clearLogsBtn) {
    clearLogsBtn.onclick = async () => {
      const ok = await notify.confirm("Sistem Logları Silinecek", "Tüm sistem denetim geçmişi kalıcı olarak silinecektir. Devam edilsin mi?", "Evet, Tümünü Temizle");
      if (!ok) return;

      try {
        const res = await fetch('../backend/api/admin/manager.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ auth_user_id: currentUser.id, action: 'clear_audit_logs' })
        });
        const data = await res.json();
        notify.success("Loglar Temizlendi", data.message);
        loadAdminDashboard();
      } catch (err) {
        notify.error("Hata", err.message);
      }
    };
  }
}

async function loadAdminDashboard() {
  if (!currentUser) {
    const saved = localStorage.getItem('islandprop_user');
    if (saved) currentUser = JSON.parse(saved);
  }

  if (!currentUser || !['admin', 'superadmin'].includes(currentUser.role)) return;

  try {
    const res = await fetch(`../backend/api/admin/manager.php?auth_user_id=${currentUser.id}`);
    const raw = await res.text();
    if (!raw) throw new Error("Sunucu boş yanıt döndü.");
    const data = JSON.parse(raw);

    if (data.status === 'success') {
      const isSuper = data.is_superadmin;
      document.getElementById('adminModalTitle').innerText = isSuper ? "🛡️ IslandProp Sistem Yönetim Paneli" : "IslandProp Yönetim Paneli";
      document.getElementById('adminModalSubtitle').innerText = `Yönetici: ${currentUser.full_name} (${isSuper ? 'Sistem Yöneticisi' : 'Admin'})`;

      const clearLogsBtn = document.getElementById('clearLogsBtn');
      if (clearLogsBtn) {
        if (isSuper) clearLogsBtn.style.display = 'inline-block';
        else clearLogsBtn.style.display = 'none';
      }

      const pendingCount = parseInt(data.pending_count || 0);
      const badge = document.getElementById('adminNotificationBadge');
      const tabBadge = document.getElementById('adminTabBadge');

      if (pendingCount > 0) {
        if (badge) { badge.innerText = pendingCount; badge.style.display = 'flex'; }
        if (tabBadge) { tabBadge.innerText = pendingCount; tabBadge.style.display = 'inline-block'; }
      } else {
        if (badge) badge.style.display = 'none';
        if (tabBadge) tabBadge.style.display = 'none';
      }

      allAdminProperties = data.properties || [];
      renderAdminPropertiesTable(allAdminProperties);

      const usersTbody = document.getElementById('adminUsersTbody');
      usersTbody.innerHTML = '';
      (data.users || []).forEach(u => {
        const row = document.createElement('tr');
        const canManage = isSuper || !['admin', 'superadmin'].includes(u.role);

        row.innerHTML = `
          <td class="p-3 font-bold text-slate-800">${u.full_name} ${u.role === 'superadmin' ? '⭐' : ''}</td>
          <td class="p-3 text-slate-500">${u.email}</td>
          <td class="p-3">
            ${isSuper && u.id !== currentUser.id ? `
              <select onchange="adminChangeUserRole(${u.id}, this.value)" class="text-xs border rounded-lg p-1 font-bold bg-white focus:ring-1 focus:ring-purple-500">
                <option value="student" ${u.role==='student'?'selected':''}>Öğrenci</option>
                <option value="landlord" ${u.role==='landlord'?'selected':''}>Ev Sahibi</option>
                <option value="agent" ${u.role==='agent'?'selected':''}>Emlakçı</option>
                <option value="admin" ${u.role==='admin'?'selected':''}>Admin</option>
              </select>
            ` : `<span class="font-bold text-slate-700 uppercase text-[10px]">${u.role}</span>`}
          </td>
          <td class="p-3">
            <span class="px-2 py-0.5 rounded text-[10px] font-bold ${u.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'}">
              ${u.status}
            </span>
          </td>
          <td class="p-3 text-right whitespace-nowrap">
            ${canManage && u.id !== currentUser.id ? `
              <button onclick="adminToggleUserStatus(${u.id}, '${u.status === 'active' ? 'suspended' : 'active'}')" class="px-2.5 py-1 rounded-lg ${u.status === 'active' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'} font-bold transition">
                ${u.status === 'active' ? 'Dondur' : 'Aktifleştir'}
              </button>
            ` : `<span class="text-slate-400 text-[10px]">Kısıtlı</span>`}
          </td>
        `;
        usersTbody.appendChild(row);
      });

      const logsTbody = document.getElementById('adminLogsTbody');
      logsTbody.innerHTML = '';
      (data.logs || []).forEach(l => {
        const row = document.createElement('tr');
        row.innerHTML = `
          <td class="p-2.5 text-slate-400 whitespace-nowrap">${l.created_at}</td>
          <td class="p-2.5 font-bold text-slate-800">${l.user_name || 'Sistem'}</td>
          <td class="p-2.5 text-purple-700 font-bold">${l.action}</td>
          <td class="p-2.5">${l.entity_type} (#${l.entity_id})</td>
          <td class="p-2.5 text-[10px] text-slate-500 truncate max-w-xs">${l.new_values || '-'}</td>
        `;
        logsTbody.appendChild(row);
      });

      lucide.createIcons();
    }
  } catch (err) {
    notify.error("Yükleme Hatası", err.message);
  }
}

function renderAdminPropertiesTable(props) {
  const propsTbody = document.getElementById('adminPropsTbody');
  if (!propsTbody) return;
  propsTbody.innerHTML = '';

  if (props.length === 0) {
    propsTbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-slate-400 font-semibold text-xs">Bu kriterde ilan bulunamadı.</td></tr>`;
    return;
  }

  props.forEach(p => {
    const row = document.createElement('tr');

    let statusBadge = '';
    if (p.status === 'pending') {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300">🟡 Onay Bekliyor</span>`;
    } else if (p.status === 'active') {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200">🟢 Yayında</span>`;
    } else if (p.status === 'rented') {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-purple-100 text-purple-800 border border-purple-200">🟣 Kiralandı</span>`;
    } else if (p.status === 'rejected') {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-red-100 text-red-800 border border-red-200">🔴 Reddedildi</span>`;
    } else {
      statusBadge = `<span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-slate-100 text-slate-700">${p.status}</span>`;
    }

    row.innerHTML = `
      <td class="p-3 font-bold text-slate-800">
        <span class="text-blue-600 block text-[10px]">#${p.id} • ${p.district_name || 'Gazimağusa'}</span>
        ${p.title}
      </td>
      <td class="p-3">
        <strong class="block text-slate-800 text-xs">${p.owner_name || 'Bilinmiyor'}</strong>
        <span class="text-[10px] text-slate-400">${p.owner_phone || ''}</span>
      </td>
      <td class="p-3 font-semibold text-blue-600">£${p.price}</td>
      <td class="p-3 text-slate-400 text-[10px]">${p.created_at_formatted || 'Yeni'}</td>
      <td class="p-3">${statusBadge}</td>
      <td class="p-3 text-right space-x-1 whitespace-nowrap">
        ${p.status === 'pending' ? `
          <button onclick="adminChangePropStatus(${p.id}, 'active')" class="px-2.5 py-1 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold transition shadow-2xs">
            ✓ Onayla
          </button>
          <button onclick="adminChangePropStatus(${p.id}, 'rejected')" class="px-2.5 py-1 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold transition shadow-2xs">
            ✕ Reddet
          </button>
        ` : (p.status === 'active' ? `
          <button onclick="adminChangePropStatus(${p.id}, 'archived')" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition">
            Dondur
          </button>
        ` : `
          <button onclick="adminChangePropStatus(${p.id}, 'active')" class="px-2.5 py-1 rounded-lg bg-blue-100 hover:bg-blue-200 text-blue-800 font-bold transition">
            Yayına Al
          </button>
        `)}
        <button onclick="adminDeleteProperty(${p.id})" class="px-2.5 py-1 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 font-bold transition">
          Sil
        </button>
      </td>
    `;
    propsTbody.appendChild(row);
  });
}

window.adminFilterProps = function(type) {
  if (type === 'pending') {
    const filtered = allAdminProperties.filter(p => p.status === 'pending');
    renderAdminPropertiesTable(filtered);
  } else {
    renderAdminPropertiesTable(allAdminProperties);
  }
};

window.adminChangePropStatus = async function(propId, newStatus) {
  try {
    const res = await fetch('../backend/api/admin/manager.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ auth_user_id: currentUser.id, action: 'update_property_status', property_id: propId, status: newStatus })
    });
    const d = await res.json();
    notify.success("İlan Durumu Güncellendi", d.message);
    loadAdminDashboard();
    fetchProperties();
  } catch (e) {
    notify.error("Hata", e.message);
  }
};

window.adminDeleteProperty = async function(propId) {
  const ok = await notify.confirm("İlan Silinecek", "Bu ilanı tamamen silmek istediğinize emin misiniz?", "Evet, Sil");
  if (!ok) return;

  try {
    const res = await fetch('../backend/api/admin/manager.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ auth_user_id: currentUser.id, action: 'delete_property', property_id: propId })
    });
    const d = await res.json();
    notify.success("İlan Silindi", d.message);
    loadAdminDashboard();
    fetchProperties();
  } catch (e) {
    notify.error("Hata", e.message);
  }
};

window.adminToggleUserStatus = async function(targetUserId, newStatus) {
  try {
    const res = await fetch('../backend/api/admin/manager.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ auth_user_id: currentUser.id, action: 'toggle_user_status', target_user_id: targetUserId, status: newStatus })
    });
    const d = await res.json();
    notify.success("Kullanıcı Güncellendi", d.message);
    loadAdminDashboard();
  } catch (e) {
    notify.error("Hata", e.message);
  }
};

window.adminChangeUserRole = async function(targetUserId, newRole) {
  try {
    const res = await fetch('../backend/api/admin/manager.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ auth_user_id: currentUser.id, action: 'change_user_role', target_user_id: targetUserId, new_role: newRole })
    });
    const d = await res.json();
    notify.success("Rol Değiştirildi", d.message);
    loadAdminDashboard();
  } catch (e) {
    notify.error("Hata", e.message);
  }
};