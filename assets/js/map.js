/* Posting map — Leaflet markers for every office, clustered, with an
   employee list in the popup. Data comes from window.HR_MAP (employees/map.php). */
(function () {
    var cfg = window.HR_MAP;
    if (!cfg || typeof L === 'undefined') return;
    var el = document.getElementById('postingMap');
    if (!el) return;

    var map = L.map(el, { scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    map.on('click', function () { map.scrollWheelZoom.enable(); });
    map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* A numbered pin: the count of employees posted at that office. */
    function pin(count, kind, focused) {
        var empty = count === 0;
        return L.divIcon({
            className: 'map-pin-wrap',
            html: '<div class="map-pin' + (empty ? ' empty' : '') + (kind === 'department' ? ' dept' : '')
                + (focused ? ' focus' : '') + '"><span>' + count + '</span></div>',
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -32]
        });
    }

    function popupHtml(p) {
        var h = '<div class="map-popup"><div class="mp-head"><strong>' + esc(p.name) + '</strong>';
        if (p.kind === 'department') h += ' <span class="mp-kind">department</span>';
        if (p.address) h += '<div class="mp-addr">' + esc(p.address) + '</div>';
        if (p.kind === 'office' && p.dept) h += '<div class="mp-addr">' + esc(p.dept) + '</div>';
        if (p.via) h += '<div class="mp-via">' + esc(p.via) + '</div>';
        h += '</div>';

        if (!p.employees.length) {
            h += '<div class="mp-empty">No employee is currently posted here.</div></div>';
            return h;
        }

        var depts = Object.keys(p.departments || {});
        if (depts.length) {
            h += '<div class="mp-depts">';
            depts.forEach(function (d) { h += '<span class="mp-chip">' + esc(d) + ' · ' + p.departments[d] + '</span>'; });
            h += '</div>';
        }

        h += '<div class="mp-count">' + p.employees.length + ' employee' + (p.employees.length === 1 ? '' : 's') + ' posted here</div><div class="mp-list">';
        p.employees.forEach(function (e) {
            var isFocus = cfg.focusEmployee && e.id === cfg.focusEmployee;
            h += '<a class="mp-emp' + (isFocus ? ' focus' : '') + '" href="' + esc(e.url) + '">'
               + '<img src="' + esc(e.photo) + '" alt="">'
               + '<span class="mp-emp-t"><span class="mp-emp-n">' + esc(e.name) + '</span>'
               + '<span class="mp-emp-d">' + esc(e.designation || '') + (e.scale ? ' (' + esc(e.scale) + ')' : '') + '</span>'
               + '<span class="mp-emp-m">' + esc(e.code) + (e.since ? ' · since ' + esc(e.since) : '') + '</span></span></a>';
        });
        h += '</div></div>';
        return h;
    }

    var layer = (typeof L.markerClusterGroup === 'function')
        ? L.markerClusterGroup({ maxClusterRadius: 44, spiderfyOnMaxZoom: true, showCoverageOnHover: false,
                                 disableClusteringAtZoom: 14 })
        : L.layerGroup();

    var markers = {};
    var bounds = [];
    cfg.points.forEach(function (p) {
        try {
            var isFocus = cfg.focus && p.id === cfg.focus;
            var m = L.marker([p.lat, p.lng], {
                icon: pin(p.count, p.kind, isFocus),
                title: p.name + ' — ' + p.count,
                zIndexOffset: isFocus ? 1000 : 0
            });
            m.bindPopup(popupHtml(p), { maxWidth: 420, minWidth: 360, maxHeight: 420 });
            layer.addLayer(m);
            markers[p.id] = m;
            bounds.push([p.lat, p.lng]);
        } catch (err) {
            /* one bad location must not blank the whole map */
            if (window.console) console.error('Posting map: could not place "' + p.name + '"', err);
        }
    });

    /* Order matters: the view must be set and the container measured BEFORE the
       cluster layer is added, otherwise the plugin computes an empty viewport
       (a card that lays out late, a hidden tab or a collapsed sidebar) and
       silently renders no markers. */
    function applyView() {
        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
        } else if (bounds.length === 1) {
            map.setView(bounds[0], 13);
        } else {
            map.setView(cfg.center, cfg.zoom);
        }
    }
    applyView();
    map.invalidateSize();
    map.addLayer(layer);

    /* Re-measure whenever the container actually gets (or changes) its size. */
    /* Opened from an employee profile: centre on that employee's office. */
    function openFocus() {
        if (!cfg.focus || !markers[cfg.focus]) return;
        var m = markers[cfg.focus];
        var show = function () { try { m.openPopup(); } catch (e) {} };
        map.setView(m.getLatLng(), 15);
        if (layer.zoomToShowLayer) { layer.zoomToShowLayer(m, show); } else { show(); }
        /* zoomToShowLayer's callback can be skipped while the pane is still
           laying out, so make sure the popup ends up open either way. */
        setTimeout(show, 400);
        setTimeout(show, 1200);
    }
    openFocus();

    function measured() { return el.offsetWidth > 0 && el.offsetHeight > 0; }
    var settled = measured();
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(function () {
            map.invalidateSize();
            if (!settled && measured()) { settled = true; if (cfg.focus) { openFocus(); } else { applyView(); } }
        }).observe(el);
    }
    window.addEventListener('resize', function () { map.invalidateSize(); });

    /* Clicking an office in the side list flies to its marker and opens the popup. */
    document.querySelectorAll('.map-office-list [data-loc]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            var m = markers[this.dataset.loc];
            if (!m) return;
            var open = function () { m.openPopup(); };
            map.flyTo(m.getLatLng(), 15, { duration: 0.6 });
            if (layer.zoomToShowLayer) { layer.zoomToShowLayer(m, open); } else { setTimeout(open, 650); }
        });
    });

    setTimeout(function () { map.invalidateSize(); }, 250);
})();
