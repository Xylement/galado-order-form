(function ($) {
    'use strict';

    if (typeof galadoFonts === 'undefined') return;

    var fonts = galadoFonts.fonts;
    var selectedFont = '';
    var $input = $('#galado-fp-text');
    var $grid = $('#galado-fp-grid');
    var $hidden = $('#galado-fp-selected');
    var $badge = $('#galado-fp-badge');
    var $badgeName = $('#galado-fp-badge-name');

    function renderPreviews(text) {
        if (!text.trim()) {
            $grid.html('<div class="galado-fp-placeholder">Type a name above to see font previews</div>');
            return;
        }

        var html = '';
        for (var i = 0; i < fonts.length; i++) {
            var f = fonts[i];
            var sel = selectedFont === f ? ' selected' : '';
            html += '<div class="galado-fp-card' + sel + '" data-font="' + f + '">';
            html += '<span class="font-name">' + f + '</span>';
            html += '<span class="font-sample" style="font-family:\'' + f + '\';">' + escapeHtml(text) + '</span>';
            html += '</div>';
        }
        $grid.html(html);

        // Bind tap-to-select
        $grid.find('.galado-fp-card').on('click', function () {
            selectedFont = $(this).data('font');
            $hidden.val(selectedFont);
            $badgeName.text(selectedFont);
            $badge.show();
            $grid.find('.galado-fp-card').removeClass('selected');
            $(this).addClass('selected');
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Live preview on input
    $input.on('input', function () {
        renderPreviews($(this).val());
    });

    // Re-render if text already present (e.g. browser back)
    if ($input.val()) {
        renderPreviews($input.val());
    }

})(jQuery);
