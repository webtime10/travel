/**
 * Main
 */

'use strict';

let menu,
  animate;
document.addEventListener('DOMContentLoaded', function () {
  // class for ios specific styles
  if (navigator.userAgent.match(/iPhone|iPad|iPod/i)) {
    document.body.classList.add('ios');
  }
});

(function () {
  // Keep left menu scroll position across page navigations
  // sessionStorage works over http; window.name also works for file:// (double-click HTML)
  const MENU_SCROLL_KEY = 'sneat-menu-scroll-top';
  const MENU_SCROLL_NAME_PREFIX = 'sneat-menu-scroll:';

  function getMenuInner() {
    return document.querySelector('#layout-menu .menu-inner');
  }

  function saveMenuScroll() {
    const el = getMenuInner();
    if (!el) return;
    const value = String(el.scrollTop);
    try {
      sessionStorage.setItem(MENU_SCROLL_KEY, value);
    } catch (e) {}
    window.name = MENU_SCROLL_NAME_PREFIX + value;
  }

  function restoreMenuScroll() {
    const el = getMenuInner();
    if (!el) return false;
    let saved = null;
    try {
      saved = sessionStorage.getItem(MENU_SCROLL_KEY);
    } catch (e) {}
    if (saved === null && String(window.name).indexOf(MENU_SCROLL_NAME_PREFIX) === 0) {
      saved = String(window.name).slice(MENU_SCROLL_NAME_PREFIX.length);
    }
    if (saved === null) return false;
    el.scrollTop = Number(saved) || 0;
    if (window.Helpers && window.Helpers.mainMenu && window.Helpers.mainMenu._perfectScrollbar) {
      window.Helpers.mainMenu._perfectScrollbar.update();
    }
    return true;
  }

  // Initialize menu
  //-----------------

  let layoutMenuEl = document.querySelectorAll('#layout-menu');
  layoutMenuEl.forEach(function (element) {
    menu = new Menu(element, {
      orientation: 'vertical',
      closeChildren: false
    });
    window.Helpers.mainMenu = menu;

    // Prefer saved scroll; only jump to active item on first visit
    if (!restoreMenuScroll()) {
      window.Helpers.scrollToActive((animate = false));
    } else {
      // PerfectScrollbar may reset scroll after init — restore again next frame
      requestAnimationFrame(function () {
        restoreMenuScroll();
      });
    }
  });

  // Initialize menu togglers and bind click on each
  let menuToggler = document.querySelectorAll('.layout-menu-toggle');
  menuToggler.forEach(item => {
    item.addEventListener('click', event => {
      event.preventDefault();
      window.Helpers.toggleCollapsed();
    });
  });

  // Display menu toggle (layout-menu-toggle) on hover with delay
  let delay = function (elem, callback) {
    let timeout = null;
    elem.onmouseenter = function () {
      // Set timeout to be a timer which will invoke callback after 300ms (not for small screen)
      if (!Helpers.isSmallScreen()) {
        timeout = setTimeout(callback, 300);
      } else {
        timeout = setTimeout(callback, 0);
      }
    };

    elem.onmouseleave = function () {
      // Clear any timers set to timeout
      document.querySelector('.layout-menu-toggle').classList.remove('d-block');
      clearTimeout(timeout);
    };
  };
  if (document.getElementById('layout-menu')) {
    delay(document.getElementById('layout-menu'), function () {
      // not for small screen
      if (!Helpers.isSmallScreen()) {
        document.querySelector('.layout-menu-toggle').classList.add('d-block');
      }
    });
  }

  // Display in main menu when menu scrolls
  let menuInnerContainer = document.getElementsByClassName('menu-inner'),
    menuInnerShadow = document.getElementsByClassName('menu-inner-shadow')[0];
  if (menuInnerContainer.length > 0 && menuInnerShadow) {
    menuInnerContainer[0].addEventListener('ps-scroll-y', function () {
      saveMenuScroll();
      if (this.querySelector('.ps__thumb-y').offsetTop) {
        menuInnerShadow.style.display = 'block';
      } else {
        menuInnerShadow.style.display = 'none';
      }
    });
    menuInnerContainer[0].addEventListener('scroll', saveMenuScroll);
  }

  // Save before leaving the page (menu link clicks)
  document.querySelectorAll('#layout-menu a.menu-link[href]').forEach(function (link) {
    link.addEventListener('click', function () {
      const href = link.getAttribute('href');
      if (href && href !== 'javascript:void(0);' && !href.startsWith('#')) {
        saveMenuScroll();
      }
    });
  });
  window.addEventListener('beforeunload', saveMenuScroll);

  // Init helpers & misc
  // --------------------

  // Init BS Tooltip
  const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Accordion active class
  const accordionActiveFunction = function (e) {
    if (e.type == 'show.bs.collapse' || e.type == 'show.bs.collapse') {
      e.target.closest('.accordion-item').classList.add('active');
    } else {
      e.target.closest('.accordion-item').classList.remove('active');
    }
  };

  const accordionTriggerList = [].slice.call(document.querySelectorAll('.accordion'));
  const accordionList = accordionTriggerList.map(function (accordionTriggerEl) {
    accordionTriggerEl.addEventListener('show.bs.collapse', accordionActiveFunction);
    accordionTriggerEl.addEventListener('hide.bs.collapse', accordionActiveFunction);
  });

  // Auto update layout based on screen size
  window.Helpers.setAutoUpdate(true);

  // Toggle Password Visibility
  window.Helpers.initPasswordToggle();

  // Speech To Text
  window.Helpers.initSpeechToText();

  // Manage menu expanded/collapsed with templateCustomizer & local storage
  //------------------------------------------------------------------

  // If current layout is horizontal OR current window screen is small (overlay menu) than return from here
  if (window.Helpers.isSmallScreen()) {
    return;
  }

  // If current layout is vertical and current window screen is > small

  // Auto update menu collapsed/expanded based on the themeConfig
      window.Helpers.setCollapsed(true, false);
})();
// Utils
function isMacOS() {
  return /Mac|iPod|iPhone|iPad/.test(navigator.userAgent);
}
