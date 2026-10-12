(function () {
  var supplierInput = document.querySelector('#supplier-search-block input[data-endpoint]');
  var supplierResults = document.getElementById('supplier-search-results');
  var supplierSearchBlock = document.getElementById('supplier-search-block');
  var supplierId = supplierSearchBlock && document.getElementById(supplierSearchBlock.dataset.supplierIdInput);
  var supplierSearchRow = supplierSearchBlock && supplierSearchBlock.querySelector('.js-search-supplier-row');
  var supplierTemplate = document.querySelector('#supplier-search-result-template .js-supplier-search-result-col');
  var supplierLoading = document.getElementById('supplier-search-loading-notice');
  var supplierEmpty = document.getElementById('supplier-search-empty-result-warn');
  var supplierError = document.getElementById('supplier-search-error-notice');
  var supplierTimer = null;
  var supplierRequest = null;
  var supplierSearchNumber = 0;

  function clearSupplierResults() {
    supplierResults.innerHTML = '';
    supplierInput.setAttribute('aria-expanded', 'false');
    supplierInput.removeAttribute('aria-busy');
  }

  function clearSupplierNotices() {
    supplierLoading.classList.add('d-none');
    supplierEmpty.classList.add('d-none');
    supplierError.classList.add('d-none');
  }

  function showSupplierCard(item, selected) {
    var column = supplierTemplate.cloneNode(true);
    var card = column.querySelector('.js-supplier-search-result');
    var chooseButton = column.querySelector('.js-choose-supplier-btn');
    var changeButton = column.querySelector('.js-change-supplier-btn');
    var detailsButton = column.querySelector('.js-details-supplier-btn');
    column.setAttribute('role', 'listitem');

    column.querySelector('.js-supplier-name').textContent = item.name;
    column.querySelector('.js-supplier-id').textContent = item.id_supplier;
    detailsButton.href = item.details_url;

    if (selected) {
      card.classList.add('border-success');
      chooseButton.classList.add('d-none');
      changeButton.classList.remove('d-none');
    }

    chooseButton.addEventListener('click', function () {
      supplierId.value = item.id_supplier;
      supplierInput.value = item.name;
      supplierSearchRow.classList.add('d-none');
      supplierResults.innerHTML = '';
      clearSupplierResults();
      clearSupplierNotices();
      supplierResults.appendChild(showSupplierCard(item, true));
    });

    changeButton.addEventListener('click', function () {
      supplierId.value = '';
      supplierInput.value = '';
      supplierSearchRow.classList.remove('d-none');
      supplierResults.innerHTML = '';
      clearSupplierResults();
      clearSupplierNotices();
      supplierInput.focus();
    });

    return column;
  }

  if (supplierInput && supplierId && supplierResults && supplierSearchBlock && supplierSearchRow && supplierTemplate) {
    var initialSupplierId = supplierId.value;
    if (initialSupplierId) {
      var initialSupplier = {
        id_supplier: initialSupplierId,
        name: supplierInput.value,
        details_url: supplierSearchBlock.dataset.selectedSupplierDetailsUrl
      };
      supplierSearchRow.classList.add('d-none');
      supplierResults.appendChild(showSupplierCard(initialSupplier, true));
    }

    supplierInput.addEventListener('keydown', function (event) {
      var options = Array.prototype.slice.call(supplierResults.querySelectorAll('.js-choose-supplier-btn:not(.d-none)'));
      if (event.key === 'ArrowDown' && options.length) {
        event.preventDefault();
        options[0].focus();
      } else if (event.key === 'Escape') {
        clearSupplierResults();
      }
    });
    supplierResults.addEventListener('keydown', function (event) {
      var options = Array.prototype.slice.call(supplierResults.querySelectorAll('.js-choose-supplier-btn:not(.d-none)'));
      var index = options.indexOf(document.activeElement);
      if (event.key === 'ArrowDown' && options[index + 1]) {
        event.preventDefault();
        options[index + 1].focus();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        if (index > 0) { options[index - 1].focus(); } else { supplierInput.focus(); }
      } else if (event.key === 'Escape') {
        clearSupplierResults();
        supplierInput.focus();
      }
    });
    supplierInput.addEventListener('input', function () {
      supplierId.value = '';
      supplierResults.innerHTML = '';
      supplierSearchRow.classList.remove('d-none');
      clearSupplierNotices();
      supplierError.classList.add('d-none');
      clearTimeout(supplierTimer);
      supplierSearchNumber++;
      var searchNumber = supplierSearchNumber;
      if (supplierRequest) { supplierRequest.abort(); supplierRequest = null; }
      var q = supplierInput.value.trim();
      if (q.length < 2) { clearSupplierResults(); return; }

      supplierTimer = setTimeout(function () {
        supplierInput.setAttribute('aria-busy', 'true');
        supplierInput.setAttribute('aria-expanded', 'true');
        supplierLoading.classList.remove('d-none');
        var url = new URL(supplierInput.dataset.endpoint, window.location.href);
        url.searchParams.set('q', q);
        var request = new XMLHttpRequest();
        supplierRequest = request;
        request.open('GET', url.toString(), true);
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.onreadystatechange = function () {
          if (request.readyState !== XMLHttpRequest.DONE || searchNumber !== supplierSearchNumber) { return; }
          supplierInput.removeAttribute('aria-busy');
          supplierRequest = null;
          if (request.status < 200 || request.status >= 300) {
            clearSupplierNotices();
            supplierError.classList.remove('d-none');
            return;
          }
          var items;
          try { items = JSON.parse(request.responseText); } catch (error) { items = null; }
          if (!Array.isArray(items)) {
            clearSupplierNotices();
            supplierError.classList.remove('d-none');
            return;
          }
          clearSupplierNotices();
          supplierResults.innerHTML = '';
          if (!items.length) {
            supplierEmpty.classList.remove('d-none');
            return;
          }
          supplierInput.setAttribute('aria-expanded', 'true');
          items.forEach(function (item) {
            supplierResults.appendChild(showSupplierCard(item, false));
          });
        };
        request.send();
      }, 300);
    });
  }

  var input = document.getElementById('product-search');
  if (!input) { return; }
  var results = document.getElementById('product-results');
  var idProduct = document.getElementById('line-id-product');
  var idPa = document.getElementById('line-id-pa');
  var cost = document.getElementById('line-cost');
  var form = document.getElementById('add-line-form');
  var timer = null;
  var productRequest = null;
  var productSearchNumber = 0;

  function showProductMessage(message) {
    results.innerHTML = '';
    var notice = document.createElement('div');
    notice.className = 'list-group-item text-muted';
    notice.setAttribute('role', 'status');
    notice.textContent = message;
    results.appendChild(notice);
    input.setAttribute('aria-expanded', 'true');
  }

  input.addEventListener('input', function () {
    idProduct.value = '';
    idPa.value = '0';
    clearTimeout(timer);
    productSearchNumber++;
    var searchNumber = productSearchNumber;
    if (productRequest) { productRequest.abort(); productRequest = null; }
    var q = input.value.trim();
    if (q.length < 2) {
      results.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-busy');
      return;
    }

    timer = setTimeout(function () {
      var url = new URL(input.dataset.endpoint, window.location.origin);
      url.searchParams.set('q', q);
      input.setAttribute('aria-busy', 'true');
      showProductMessage('Buscando productos...');
      var request = new XMLHttpRequest();
      productRequest = request;
      request.open('GET', url.toString(), true);
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      request.onreadystatechange = function () {
        if (request.readyState !== XMLHttpRequest.DONE || searchNumber !== productSearchNumber) { return; }
        productRequest = null;
        input.removeAttribute('aria-busy');

        if (request.status < 200 || request.status >= 300) {
          showProductMessage('No se pudieron cargar los productos. Verifica tus permisos e inténtalo de nuevo.');
          return;
        }

        var items;
        try { items = JSON.parse(request.responseText); } catch (error) { items = null; }
        if (!Array.isArray(items)) {
          showProductMessage('La respuesta de búsqueda no es válida.');
          return;
        }
        if (!items.length) {
          showProductMessage('No se encontraron productos.');
          return;
        }

        results.innerHTML = '';
        input.setAttribute('aria-expanded', 'true');
        items.forEach(function (item) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'list-group-item list-group-item-action';
          btn.setAttribute('role', 'option');
          btn.textContent = item.label + (item.reference ? ' (' + item.reference + ')' : '');
          btn.addEventListener('click', function () {
            idProduct.value = item.id_product;
            idPa.value = item.id_product_attribute;
            cost.value = item.cost;
            input.value = btn.textContent;
            results.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
          });
          results.appendChild(btn);
        });
      };
      request.send();
    }, 250);
  });

  input.addEventListener('keydown', function (event) {
    var options = Array.prototype.slice.call(results.querySelectorAll('[role=option]'));
    if (event.key === 'ArrowDown' && options.length) {
      event.preventDefault();
      options[0].focus();
    } else if (event.key === 'Escape') {
      results.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
    }
  });

  results.addEventListener('keydown', function (event) {
    var options = Array.prototype.slice.call(results.querySelectorAll('[role=option]'));
    var index = options.indexOf(document.activeElement);
    if (event.key === 'ArrowDown' && options[index + 1]) {
      event.preventDefault();
      options[index + 1].focus();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      if (index > 0) { options[index - 1].focus(); } else { input.focus(); }
    } else if (event.key === 'Escape') {
      results.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      input.focus();
    }
  });

  form.addEventListener('submit', function (event) {
    if (!idProduct.value) { event.preventDefault(); input.focus(); }
  });
})();
