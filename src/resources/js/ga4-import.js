/**
 * The GA4 import utility.
 *
 * Two small jobs: copy the redirect address onto the clipboard, and once a
 * Google connection exists, ask our own controller for the account's property
 * list and fill the picker. Every network call it makes is to this site; it
 * never talks to Google directly.
 */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  function wireCopyButton(root) {
    var button = root.querySelector('[data-ga4-copy]');
    var field = root.querySelector('[data-ga4-redirect]');

    if (!button || !field) {
      return;
    }

    button.addEventListener('click', function () {
      var value = field.value || field.textContent || '';

      var done = function () {
        var original = button.getAttribute('data-original') || button.textContent;
        button.setAttribute('data-original', original);
        button.textContent = button.getAttribute('data-copied') || 'Copied';
        window.setTimeout(function () {
          button.textContent = original;
        }, 1500);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(done, function () {});
      } else if (field.select) {
        field.select();
        try {
          document.execCommand('copy');
          done();
        } catch (e) {
          /* no clipboard: the value is selected for a manual copy */
        }
      }
    });
  }

  function loadProperties(root) {
    var select = root.querySelector('[data-ga4-property]');
    var status = root.querySelector('[data-ga4-property-status]');
    var url = root.getAttribute('data-properties-url');

    if (!select || !url) {
      return;
    }

    if (status) {
      status.textContent = status.getAttribute('data-loading') || 'Loading properties...';
    }

    fetch(url, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (data.error) {
          if (status) {
            status.textContent = data.error;
          }
          return;
        }

        var properties = data.properties || [];

        if (!properties.length) {
          if (status) {
            status.textContent = status.getAttribute('data-empty') || 'No GA4 properties found for this account.';
          }
          return;
        }

        select.innerHTML = '';
        properties.forEach(function (property) {
          var option = document.createElement('option');
          option.value = property.id;
          option.textContent = property.account
            ? property.name + ' (' + property.account + ')'
            : property.name;
          option.setAttribute('data-name', property.name);
          select.appendChild(option);
        });

        select.disabled = false;
        syncPropertyName(root, select);

        if (status) {
          status.textContent = '';
        }
      })
      .catch(function () {
        if (status) {
          status.textContent = status.getAttribute('data-failed') || 'Could not load the property list.';
        }
      });

    select.addEventListener('change', function () {
      syncPropertyName(root, select);
    });
  }

  function syncPropertyName(root, select) {
    var hidden = root.querySelector('[data-ga4-property-name]');
    var option = select.options[select.selectedIndex];

    if (hidden && option) {
      hidden.value = option.getAttribute('data-name') || option.textContent || '';
    }
  }

  ready(function () {
    var root = document.querySelector('[data-ga4-import]');

    if (!root) {
      return;
    }

    wireCopyButton(root);

    if (root.getAttribute('data-connected') === '1') {
      loadProperties(root);
    }
  });
})();
