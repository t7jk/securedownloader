/**
 * PIT-11 Manager – JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initClientForm();
        initDeleteConfirmation();
        initAdminFilters();
        initTableSorting();
        initBulkDeleteButton();
        initBulkActions();
        initBrakPeselLink();
        initAccountantTabs();
        initChunkedUpload();
    });

    /**
     * Walidacja formularza podatnika.
     */
    function initClientForm() {
        var $form = $('#pit-download-form');

        if ($form.length === 0) {
            return;
        }

        $form.find('#pit_first_name, #pit_last_name').on('input', function() {
            var $el = $(this);
            var v = $el.val();
            if (v !== v.toUpperCase()) {
                $el.val(v.toUpperCase());
            }
        });

        $form.on('submit', function(e) {
            var hasError = false;
            var errors = [];

            var pesel = $('#pit_pesel').val().trim();
            var firstName = $('#pit_first_name').val().trim();
            var lastName = $('#pit_last_name').val().trim();

            $('.pit-field-error').remove();
            $form.find('input, select').removeClass('pit-error-border');

            if (!/^\d{11}$/.test(pesel)) {
                errors.push({ field: 'pit_pesel', message: pitManager.errorPesel });
                hasError = true;
            }

            if (!firstName) {
                errors.push({ field: 'pit_first_name', message: pitManager.errorFirstName || pitManager.errorName });
                hasError = true;
            }

            if (!lastName) {
                errors.push({ field: 'pit_last_name', message: pitManager.errorLastName || pitManager.errorName });
                hasError = true;
            }

            if (hasError) {
                e.preventDefault();

                $.each(errors, function(i, error) {
                    $('#' + error.field).addClass('pit-error-border');
                    $('#' + error.field).after('<span class="pit-field-error" style="color:#dc3232;font-size:12px;margin-top:4px;display:block;">' + error.message + '</span>');
                });

                return false;
            }

            return true;
        });
    }

    /**
     * Potwierdzenie usunięcia pliku.
     */
    function initDeleteConfirmation() {
        $(document).on('click', '.pit-confirm-delete', function(e) {
            var message = pitManager.confirmDelete || 'Czy na pewno usunąć ten plik?';
            
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    }

    /**
     * Filtrowanie po roku w panelu administratora.
     */
    function initAdminFilters() {
        var $yearSelect = $('#pit-year-filter');

        if ($yearSelect.length === 0) {
            return;
        }

        $yearSelect.on('change', function() {
            var year = $(this).val();
            var url = window.location.href.split('?')[0];
            window.location.href = url + '?page=pit-manager&year=' + year;
        });
    }

    /**
     * Sortowanie tabeli po kliknięciu nagłówka.
     */
    function initTableSorting() {
        var $table = $('#pit-files-table');

        if ($table.length === 0) {
            return;
        }

        $table.find('th.sortable[data-sort="name"]').addClass('sort-asc');

        $(document).on('click', '#pit-files-table th.sortable', function(e) {
            e.preventDefault();
            
            var $header = $(this);
            var sortType = $header.data('sort');
            var $tbody = $table.find('tbody');
            var $rows = $tbody.find('tr').not(':contains("Brak dokumentów")').toArray();
            var isAsc = $header.hasClass('sort-asc');

            if ($rows.length === 0) {
                return;
            }

            $table.find('th.sortable').removeClass('sort-asc sort-desc');
            $header.addClass(isAsc ? 'sort-desc' : 'sort-asc');

            $rows.sort(function(a, b) {
                var aVal = '';
                var bVal = '';

                switch (sortType) {
                    case 'name':
                        aVal = $(a).find('td:eq(1)').data('name') || '';
                        bVal = $(b).find('td:eq(1)').data('name') || '';
                        break;
                    case 'pesel':
                        aVal = $(a).find('td:eq(2)').data('pesel') || '';
                        bVal = $(b).find('td:eq(2)').data('pesel') || '';
                        break;
                    case 'year':
                        aVal = parseInt($(a).find('td:eq(3)').data('year')) || 0;
                        bVal = parseInt($(b).find('td:eq(3)').data('year')) || 0;
                        break;
                    case 'date':
                        aVal = $(a).find('td:eq(4)').data('date') || '';
                        bVal = $(b).find('td:eq(4)').data('date') || '';
                        break;
                }

                if (sortType === 'year') {
                    return isAsc ? (bVal - aVal) : (aVal - bVal);
                } else {
                    aVal = aVal.toString().toLowerCase();
                    bVal = bVal.toString().toLowerCase();
                    
                    if (aVal < bVal) return isAsc ? 1 : -1;
                    if (aVal > bVal) return isAsc ? -1 : 1;
                    return 0;
                }
            });

            $.each($rows, function(index, row) {
                $tbody.append(row);
            });

            $tbody.find('tr').not(':contains("Brak dokumentów")').each(function(index) {
                $(this).find('.pit-lp').text(index + 1);
            });
        });
    }

    /**
     * Przycisk „Usuń zaznaczone” – wiązanie osobno, żeby działało nawet gdy lista jest pusta.
     */
    function initBulkDeleteButton() {
        $(document).on('click', '#pit-bulk-delete-btn', function(e) {
            e.preventDefault();
            var $form = $('#pit-bulk-delete-form');
            if (!$form.length) return;
            var ids = $form.find('.pit-checkbox:checked').map(function() {
                return $(this).val();
            }).get();
            if (ids.length === 0) return;
            var msg = (typeof pitManager !== 'undefined' && pitManager.confirmBulkDelete) ? pitManager.confirmBulkDelete : 'Czy na pewno usunąć zaznaczone dokumenty?';
            if (!confirm(msg)) return;
            $form.find('input[name="pit_delete_ids_csv"]').val(ids.join(','));
            $form[0].submit();
        });
    }

    /**
     * Masowe zaznaczanie i usuwanie.
     */
    function initBulkActions() {
        var $selectAll = $('#pit-select-all');
        var $checkboxes = $('.pit-checkbox');
        var $bulkActions = $('#pit-bulk-actions');
        var $selectedCount = $('#pit-selected-count');

        if ($selectAll.length === 0) {
            return;
        }

        $selectAll.on('change', function() {
            $checkboxes.prop('checked', this.checked);
            updateBulkActions();
        });

        $checkboxes.on('change', function() {
            updateBulkActions();
            
            var allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
            $selectAll.prop('checked', allChecked);
        });

        function updateBulkActions() {
            var checkedCount = $checkboxes.filter(':checked').length;
            
            if (checkedCount > 0) {
                $bulkActions.show();
                $selectedCount.text('(Zaznaczono: ' + checkedCount + ')');
            } else {
                $bulkActions.hide();
            }
        }
    }

    /**
     * Wgrywanie po 1 pliku – obsługiwane przez inline skrypt w HTML (zawsze działa, bez zależności od pitManager).
     */
    function initChunkedUpload() {
        /* Logika wgrywania po 1 pliku jest w inline script przy formularzu #pit-upload-form. */
    }

    /**
     * Przełączanie zakładek w panelu księgowego (bez przeładowania strony).
     */
    function initAccountantTabs() {
        var $panel = $('.pit-accountant-panel');
        if ($panel.length === 0) return;

        var validTabs = ['lista', 'upload', 'wzorce', 'dane-firmy'];

        function switchToTab(tabId) {
            if (!tabId || validTabs.indexOf(tabId) === -1) return;
            $('.pit-tab').removeClass('active').attr('aria-selected', 'false');
            $('.pit-tab-panel').removeClass('active').attr('hidden', true);
            var $btn = $('.pit-tab[data-pit-tab="' + tabId + '"]');
            var $target = $('#pit-tab-' + tabId);
            if ($btn.length && $target.length) {
                $btn.addClass('active').attr('aria-selected', 'true');
                $target.addClass('active').removeAttr('hidden');
            }
        }

        var params = new URLSearchParams(window.location.search);
        var pitTab = params.get('pit_tab');
        if (pitTab) {
            switchToTab(pitTab);
        } else if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('pit_tab', 'lista');
            window.history.replaceState({}, '', url.toString());
        }

        $(document).on('click', '.pit-tab', function(e) {
            e.preventDefault();
            var tabId = $(this).data('pit-tab');
            if (!tabId) return;

            $('.pit-tab').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');

            $('.pit-tab-panel').removeClass('active').attr('hidden', true);
            var $target = $('#pit-tab-' + tabId);
            if ($target.length) {
                $target.addClass('active').removeAttr('hidden');
            }

            var url = new URL(window.location.href);
            url.searchParams.set('pit_tab', tabId);
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, '', url.toString());
            }
        });
    }

    /**
     * Klik w "Nie dopasowano" pokazuje pole i przycisk Zapisz (bez zagnieżdżonego formularza).
     */
    function initBrakPeselLink() {
        $(document).on('click', '.pit-brak-pesel-link', function(e) {
            e.preventDefault();
            var $link = $(this);
            var $form = $link.siblings('.pit-set-pesel-form');
            if ($form.length) {
                $link.hide();
                $form.show();
                $form.find('.pit-set-pesel-value').focus();
            }
        });

        $(document).on('click', '.pit-set-pesel-save', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $formSpan = $btn.closest('.pit-set-pesel-form');
            var fullName = $formSpan.data('full-name');
            var value = $formSpan.find('.pit-set-pesel-value').val().replace(/\D/g, '');
            if (value.length !== 11) {
                alert(pitManager.errorPesel || 'PESEL musi składać się z 11 cyfr.');
                return;
            }
            var $data = $('#pit-pesel-form-data');
            if (!$data.length) return;
            var url = $data.data('url');
            var nonce = $data.data('nonce');
            var $f = $('<form>').attr({ method: 'post', action: url }).hide();
            $f.append($('<input>').attr({ type: 'hidden', name: 'action', value: 'pit_set_pesel_front' }));
            $f.append($('<input>').attr({ type: 'hidden', name: 'pit_set_pesel_front_nonce', value: nonce }));
            $f.append($('<input>').attr({ type: 'hidden', name: 'pit_set_pesel_full_name', value: fullName }));
            $f.append($('<input>').attr({ type: 'hidden', name: 'pit_set_pesel_value', value: value }));
            $('body').append($f);
            $f.submit();
        });
    }

})(jQuery);
