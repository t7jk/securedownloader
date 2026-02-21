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
        initBulkActions();
    });

    /**
     * Walidacja formularza podatnika.
     */
    function initClientForm() {
        var $form = $('#pit-download-form');

        if ($form.length === 0) {
            return;
        }

        $form.on('submit', function(e) {
            var hasError = false;
            var errors = [];

            var pesel = $('#pit_pesel').val().trim();
            var firstName = $('#pit_first_name').val().trim();
            var lastName = $('#pit_last_name').val().trim();
            var confirm = $('#pit_confirm').is(':checked');

            $('.pit-field-error').remove();
            $form.find('input, select').removeClass('pit-error-border');

            if (!/^\d{11}$/.test(pesel)) {
                errors.push({ field: 'pit_pesel', message: pitManager.errorPesel });
                hasError = true;
            }

            if (!firstName) {
                errors.push({ field: 'pit_first_name', message: pitManager.errorName });
                hasError = true;
            }

            if (!lastName) {
                errors.push({ field: 'pit_last_name', message: pitManager.errorName });
                hasError = true;
            }

            if (!confirm) {
                errors.push({ field: 'pit_confirm', message: pitManager.errorConfirm });
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
                        aVal = $(a).find('td:eq(2)').data('name') || '';
                        bVal = $(b).find('td:eq(2)').data('name') || '';
                        break;
                    case 'pesel':
                        aVal = $(a).find('td:eq(3)').data('pesel') || '';
                        bVal = $(b).find('td:eq(3)').data('pesel') || '';
                        break;
                    case 'year':
                        aVal = parseInt($(a).find('td:eq(4)').data('year')) || 0;
                        bVal = parseInt($(b).find('td:eq(4)').data('year')) || 0;
                        break;
                    case 'date':
                        aVal = $(a).find('td:eq(5)').data('date') || '';
                        bVal = $(b).find('td:eq(5)').data('date') || '';
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

})(jQuery);
