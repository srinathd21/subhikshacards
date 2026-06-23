<?php
/**
 * includes/script.php
 * Subhiksha Cards ERP - Common JS includes
 */
?>
<!-- jQuery is required for Select2 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- App JS -->
<script src="assets/js/app.js?v=select2-autotype-1"></script>

<script>
(function () {
    window.initSelect2AutoType = function (context) {
        if (!window.jQuery || !$.fn.select2) {
            return;
        }

        const $context = context ? $(context) : $(document);

        $context.find('select.select2-autotype, select[data-select2="true"]').each(function () {
            const $select = $(this);

            if ($select.hasClass('select2-hidden-accessible')) {
                $select.select2('destroy');
            }

            const $modal = $select.closest('.modal');
            const enableTags = String($select.data('tags')) === 'true';

            $select.select2({
                theme: 'bootstrap-5',
                width: '100%',
                dropdownParent: $modal.length ? $modal : $(document.body),
                placeholder: $select.data('placeholder') || $select.find('option:first').text() || 'Search and select',
                allowClear: false,
                tags: enableTags,
                createTag: function (params) {
                    const term = $.trim(params.term);

                    if (!enableTags || term === '') {
                        return null;
                    }

                    return {
                        id: term,
                        text: term,
                        newTag: true
                    };
                }
            });
        });
    };

    window.refreshSelect2Value = function (id) {
        if (window.jQuery && $.fn.select2) {
            $('#' + id).trigger('change.select2');
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.initSelect2AutoType(document);

        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('shown.bs.modal', function () {
                window.initSelect2AutoType(this);
            });
        });

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    });
})();
</script>
