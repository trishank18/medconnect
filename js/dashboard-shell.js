(function () {
  var path = window.location.pathname.toLowerCase();
  var isAdmin = path.indexOf('admin-dashboard.php') !== -1;
  var isDoctor = path.indexOf('doctor-dashboard.php') !== -1;
  var isPatient = path.indexOf('patient-dashboard.php') !== -1;
  if (!isAdmin && !isDoctor && !isPatient) return;

  var items = isAdmin
    ? [
        ['bi-grid-1x2-fill', 'Dashboard', 'admin-dashboard.php'],
        ['bi-person-check', 'Doctor review', '#doctor-queue-heading'],
        ['bi-file-earmark-arrow-down', 'Export data', '#export-data'],
        ['bi-shield-lock', 'Reset credentials', 'admin-reset.php']
      ]
    : isDoctor
      ? [
        { title: 'Overview', links: [
          ['bi-grid-1x2-fill', 'Dashboard', 'doctor-dashboard.php#overview-title'],
          ['bi-people', 'My Patients', 'doctor-dashboard.php#priority-queue'],
          ['bi-heart-pulse', 'Vitals Monitoring', 'doctor-dashboard.php#vitals-monitor']
        ] },
        { title: 'Care network', links: [
          ['bi-hospital', 'Doctors', 'doctor-dashboard.php#doctors-page']
        ] },
        { title: 'Operations', links: [
          ['bi-calendar3', 'Appointments', 'doctor-dashboard.php#appointments-section'],
          ['bi-capsule', 'Prescriptions', 'doctor-dashboard.php#appointments-section'],
          ['bi-chat-square-text', 'Patient Suggestions', 'doctor-dashboard.php#appointments-section']
        ] },
        { title: 'Account', links: [
          ['bi-person-circle', 'Profile', '#viewProfileModal'],
          ['bi-gear', 'Settings', '#updateProfileModal']
        ] }
      ]
      : [
          { title: 'Overview', links: [
            ['bi-grid-1x2-fill', 'Dashboard', '#patient-overview-title'],
            ['bi-heart-pulse', 'My Health', '#patient-vitals'],
            ['bi-clock-history', 'Vitals History', '#patient-vitals']
          ] },
          { title: 'My Care', links: [
            ['bi-calendar3', 'Appointments', '#appointments'],
            ['bi-person-check', 'My Doctor', '#patient-next-appointment'],
            ['bi-prescription2', 'Prescriptions', '#prescriptions'],
            ['bi-chat-square-heart', 'Doctor Suggestions', '#doctor-suggestions'],
            ['bi-robot', 'AI Predictions', 'patient-prediction.php']
          ] },
          { title: 'Account', links: [
            ['bi-person-circle', 'Profile', '#updateProfileModal'],
            ['bi-gear', 'Settings', '#updateProfileModal']
          ] }
        ];

  var aside = document.createElement('aside');
  aside.className = 'mc-sidebar';
  aside.setAttribute('aria-label', 'Workspace navigation');
  aside.innerHTML = '<div class="mc-sidebar-brand"><span aria-hidden="true"><i class="bi bi-heart-pulse-fill"></i></span><strong>MedConnect</strong></div>' +
    '<p class="mc-sidebar-kicker">' + (isAdmin ? 'Administration' : isDoctor ? 'Clinical workspace' : 'Patient workspace') + '</p>' +
    '<nav class="mc-sidebar-nav"></nav>' +
    '<div class="mc-sidebar-footer"><a href="' + (isAdmin ? 'admin-logout.php' : isDoctor ? 'logout.php?role=doctor' : 'logout.php?role=patient') + '"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign out</a></div>';
  var userName = document.body.getAttribute('data-user-name');
  var userRole = document.body.getAttribute('data-user-role');
  if (userName && userRole) {
    var userCard = document.createElement('div');
    userCard.className = 'mc-user-card';
    var avatar = document.createElement('span');
    avatar.className = 'mc-user-avatar';
    avatar.textContent = userName.trim().split(/\s+/).map(function (part) { return part.charAt(0); }).slice(0, 2).join('').toUpperCase();
    var userDetails = document.createElement('span');
    userDetails.className = 'mc-user-details';
    var name = document.createElement('strong');
    name.textContent = userName;
    var role = document.createElement('small');
    role.textContent = userRole;
    userDetails.appendChild(name);
    userDetails.appendChild(role);
    userCard.appendChild(avatar);
    userCard.appendChild(userDetails);
    var footer = aside.querySelector('.mc-sidebar-footer');
    footer.insertBefore(userCard, footer.firstChild);
  }

  var nav = aside.querySelector('nav');
  function setActiveNavLink(link) {
    nav.querySelectorAll('a').forEach(function (navLink) { navLink.classList.remove('is-active'); });
    if (link) link.classList.add('is-active');
  }

  var doctorActiveLabel = 'Dashboard';
  if (isDoctor) {
    var legacyDoctorView = new URLSearchParams(window.location.search).get('view') || '';
    doctorActiveLabel = window.location.hash === '#doctors-page' || legacyDoctorView === 'doctors' ? 'Doctors'
      : window.location.hash === '#appointments-section' || legacyDoctorView === 'appointments' ? 'Appointments'
      : window.location.hash === '#priority-queue' ? 'My Patients'
      : window.location.hash === '#vitals-monitor' ? 'Vitals Monitoring' : 'Dashboard';
  }
  var groups = (isDoctor || isPatient) ? items : [{ title: '', links: items }];
  groups.forEach(function (group) {
    if (group.title) {
      var heading = document.createElement('p');
      heading.className = 'mc-sidebar-section';
      heading.textContent = group.title;
      nav.appendChild(heading);
    }
    group.links.forEach(function (item) {
      var link = document.createElement('a');
      link.href = item[2];
      link.innerHTML = '<i class="bi ' + item[0] + '" aria-hidden="true"></i><span>' + item[1] + '</span>';
      if ((isDoctor && item[1] === doctorActiveLabel) || (!isDoctor && (item[1] === 'Dashboard' || item[2] === window.location.pathname.split('/').pop()))) {
        link.className = 'is-active';
      }
      link.addEventListener('click', function (event) {
        // Doctor workspace routes are normal page navigations with a view query.
        if (isDoctor && item[2].indexOf('doctor-dashboard.php') === 0) return;
        var target;
        try { target = document.querySelector(item[2]); } catch (error) { return; }
        if (!target) return;
        event.preventDefault();
        setActiveNavLink(link);
        if (window.innerWidth <= 900) {
          document.body.classList.remove('sidebar-open');
          var menuToggle = document.querySelector('.mc-sidebar-toggle');
          if (menuToggle) {
            menuToggle.setAttribute('aria-expanded', 'false');
            menuToggle.setAttribute('aria-label', 'Open workspace navigation');
          }
        }
        if (item[2] === '#updateProfileModal' || item[2] === '#viewProfileModal') {
          var modal = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getOrCreateInstance(target) : null;
          if (modal) modal.show();
          else target.classList.add('show');
          return;
        }
        if (item[2] === '#book-appointment' || item[2] === '#appointments' || item[2] === '#prescriptions') {
          document.body.classList.add('show-legacy-sections');
        }
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      nav.appendChild(link);
    });
  });

  var toggle = document.createElement('button');
  toggle.className = 'mc-sidebar-toggle';
  toggle.type = 'button';
  toggle.setAttribute('aria-label', 'Open workspace navigation');
  toggle.setAttribute('aria-expanded', 'false');
  toggle.innerHTML = '<i class="bi bi-list" aria-hidden="true"></i>';
  document.body.appendChild(aside);
  document.body.appendChild(toggle);
  document.body.classList.add('has-mc-sidebar');

  toggle.addEventListener('click', function () {
    var open = document.body.classList.toggle('sidebar-open');
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close workspace navigation' : 'Open workspace navigation');
  });

  if (isPatient) {
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[href="#book-appointment"], a[href="#appointments"], a[href="#prescriptions"], a[href="#doctor-suggestions"]');
      if (!link) return;
      document.body.classList.add('show-legacy-sections');
    });
  }
}());
