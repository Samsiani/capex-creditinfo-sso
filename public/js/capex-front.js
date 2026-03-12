jQuery(document).ready(function($) {

    // --- გლობალური ცვლადი ფაილების დასაგროვებლად ---
    const fileCollectors = {};

    // --- 1. ნაბიჯების მართვა (Wizard) ---
    window.capexNextStep = function() {
        const $currentStep = $('.form-step.active');
        const currentStepNum = parseInt($currentStep.data('step'));
        
        if (!validateStep($currentStep)) return;

        const nextStepNum = currentStepNum + 1;
        const $nextStep = $('#step-' + nextStepNum);

        if ($nextStep.length) {
            $currentStep.removeClass('active');
            $nextStep.addClass('active');
            updateProgressBar(nextStepNum);
            $('#capex-error-box').hide();
            $('#capex-error-list').empty();
            scrollToTop();
        }
    };

    window.capexPrevStep = function() {
        const $currentStep = $('.form-step.active');
        const currentStepNum = parseInt($currentStep.data('step'));
        const prevStepNum = currentStepNum - 1;
        const $prevStep = $('#step-' + prevStepNum);

        if ($prevStep.length) {
            $currentStep.removeClass('active');
            $prevStep.addClass('active');
            updateProgressBar(prevStepNum);
            $('#capex-error-box').hide();
            scrollToTop();
        }
    };

    function updateProgressBar(stepNum) {
        $('.step-indicator').removeClass('active completed');
        $('.step-indicator').each(function(index) {
            const indicatorStep = index + 1;
            if (indicatorStep < stepNum) {
                $(this).addClass('completed');
            } else if (indicatorStep === stepNum) {
                $(this).addClass('active');
            }
        });
    }

    function scrollToTop() {
        $('html, body').animate({
            scrollTop: $(".capex-form-wrapper").offset().top - 100
        }, 500);
    }

    function scrollToError() {
        $('html, body').animate({
            scrollTop: $("#capex-error-box").offset().top - 120
        }, 500);
    }

    // --- 2. პირობითი ლოგიკა ---
    function runConditionalLogic() {
        $('.form-group[data-logic]').each(function() {
            const $targetField = $(this);
            const logicData = $targetField.data('logic');
            
            if (!logicData || !logicData.enabled) return;

            const triggerFieldId = logicData.field;
            const operator = logicData.operator;
            const expectedValue = logicData.value;

            let actualValue = '';
            const $triggerInput = $('[name="' + triggerFieldId + '"]');

            if ($triggerInput.length > 0) {
                if ($triggerInput.is(':radio')) {
                    if ($triggerInput.filter(':checked').length > 0) {
                        actualValue = $triggerInput.filter(':checked').val();
                    }
                } else if ($triggerInput.is(':checkbox')) {
                    actualValue = $triggerInput.is(':checked') ? $triggerInput.val() : '';
                } else {
                    actualValue = $triggerInput.val();
                }
            }

            actualValue = (actualValue || '').toString().trim();
            const compareValue = (expectedValue || '').toString().trim();
            let showField = false;

            switch (operator) {
                case 'equals':
                    showField = (actualValue === compareValue);
                    break;
                case 'not_equals':
                    showField = (actualValue !== compareValue);
                    break;
                case 'empty':
                    showField = (actualValue === '');
                    break;
                case 'not_empty':
                    showField = (actualValue !== '');
                    break;
            }

            if (showField) {
                $targetField.show();
                $targetField.find(':input').prop('disabled', false);
            } else {
                $targetField.hide();
                $targetField.find(':input').prop('disabled', true);
            }
        });
    }

    $(document).on('change input', '.capex-loan-form :input', function() {
        if($(this).attr('type') !== 'file') {
            runConditionalLogic();
        }
    });
    runConditionalLogic();


    // --- 3. ვალიდაცია ---
    function validateStep($stepContainer) {
        let isValid = true;
        let errorMessages = []; 
        
        $('#capex-error-box').hide();
        $('#capex-error-list').empty();

        $stepContainer.find('input, select, textarea').filter(':visible').each(function() {
            const $input = $(this);
            if($input.prop('disabled')) return; 

            const labelText = $input.closest('.form-group').find('.form-label').text().replace('*', '').trim() || 'ველი';

            if ($input.attr('type') === 'email' && $input.val() !== '') {
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test($input.val())) {
                    isValid = false;
                    highlightError($input, true);
                    errorMessages.push('• <b>' + labelText + ':</b> არასწორი ელ-ფოსტის ფორმატი.');
                    return; 
                }
            }

            if ($input.prop('required')) {
                let val = $input.val();
                let fieldValid = true;
                let specificError = '';

                if ($input.attr('type') === 'checkbox') {
                    if (!$input.is(':checked')) {
                        fieldValid = false;
                        specificError = 'აუცილებელია თანხმობა.';
                    }
                } 
                else if ($input.attr('type') === 'radio') {
                    const name = $input.attr('name');
                    if ($('input[name="'+name+'"]:checked').length === 0) {
                        fieldValid = false;
                        specificError = 'გთხოვთ აირჩიოთ მნიშვნელობა.';
                    }
                }
                else if ($input.attr('type') === 'file') {
                    if ($input[0].files.length === 0) {
                        fieldValid = false;
                        specificError = 'ფაილის ატვირთვა სავალდებულოა.';
                    }
                }
                else {
                    if (!val || val.trim() === '') {
                        fieldValid = false;
                        specificError = 'ეს ველი სავალდებულოა.';
                    }
                }

                if (!fieldValid) {
                    isValid = false;
                    highlightError($input, true);
                    errorMessages.push('• <b>' + labelText + ':</b> ' + specificError);
                } else {
                    highlightError($input, false);
                }
            }
        });

        if (!isValid) {
            const $errorList = $('#capex-error-list');
            errorMessages.forEach(msg => {
                $errorList.append('<li>' + msg + '</li>');
            });
            $('#capex-error-box').fadeIn();
            $stepContainer.addClass('shake-animation');
            setTimeout(() => $stepContainer.removeClass('shake-animation'), 500);
            scrollToError();
        }

        return isValid;
    }

    function highlightError($input, isError) {
        const $wrapper = $input.closest('.form-group');
        if (isError) {
            $input.addClass('cx-error-border');
            if($input.is(':radio') || $input.is(':checkbox')) {
                $wrapper.find('.form-label, .checkbox-label').addClass('cx-error-text');
            }
            if($input.attr('type') === 'file') {
                $wrapper.find('.file-drop-area').addClass('cx-error-border');
            }
        } else {
            $input.removeClass('cx-error-border');
            $wrapper.find('.form-label, .checkbox-label').removeClass('cx-error-text');
            if($input.attr('type') === 'file') {
                $wrapper.find('.file-drop-area').removeClass('cx-error-border');
            }
        }
    }


    // --- 4. სხვა ფუნქციები ---
    
    $(document).on('click', '.cx-btn-now', function(e) {
        e.preventDefault();
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        const formattedDate = now.toISOString().slice(0, 16);
        const $input = $(this).prev('input[type="datetime-local"]');
        if($input.length) {
            $input.val(formattedDate).trigger('change');
            highlightError($input, false);
        }
    });

    $(document).on('input change', '.cx-input-name, .cx-input-surname', function() {
        updateConsentName();
    });
    updateConsentName();

    function updateConsentName() {
        const name = $('.cx-input-name').val() || '';
        const surname = $('.cx-input-surname').val() || '';
        let fullName = name + ' ' + surname;
        const $targets = $('[id="consent_name"]');
        if($targets.length) {
            const textToSet = fullName.trim().length > 1 ? fullName : "________________";
            $targets.text(textToSet);
        }
    }

    // --- ფაილის ატვირთვა ---
    $(document).on('change', '.file-drop-area input[type="file"]', function() {
        const $input = $(this);
        const fieldId = $input.attr('id');
        const $display = $('#display_' + fieldId);
        
        if (!fileCollectors[fieldId]) {
            fileCollectors[fieldId] = new DataTransfer();
        }

        if (this.files && this.files.length > 0) {
            for (let i = 0; i < this.files.length; i++) {
                let isDuplicate = false;
                const newFile = this.files[i];
                for(let j=0; j < fileCollectors[fieldId].files.length; j++) {
                     const existing = fileCollectors[fieldId].files[j];
                     if(existing.name === newFile.name && existing.size === newFile.size) {
                         isDuplicate = true;
                         break;
                     }
                }
                if(!isDuplicate) {
                    fileCollectors[fieldId].items.add(newFile);
                }
            }
        }

        this.files = fileCollectors[fieldId].files;

        if (this.files.length > 0) {
            let fileListHtml = '<ul style="list-style:none; padding:0; margin:5px 0;">';
            for (let i = 0; i < this.files.length; i++) {
                fileListHtml += `<li style="margin-bottom:3px;">📄 ${this.files[i].name}</li>`;
            }
            fileListHtml += '</ul>';
            fileListHtml += `<div style="margin-top:5px; font-size:12px; cursor:pointer; color:#d63638;" onclick="jQuery('#${fieldId}').trigger('clearfiles')">[x] სიის გასუფთავება</div>`;

            $display.html(fileListHtml);
            $input.closest('.file-drop-area').css('border-color', '#46b450').css('background', '#f0fff4');
            highlightError($input, false);
        } else {
            $display.text('');
            $input.closest('.file-drop-area').css('border-color', '#ccc').css('background', '#fafafa');
        }
    });

    $(document).on('clearfiles', 'input[type="file"]', function() {
        const id = $(this).attr('id');
        fileCollectors[id] = new DataTransfer(); 
        this.files = fileCollectors[id].files; 
        
        $('#display_' + id).text('');
        $(this).closest('.file-drop-area').css('border-color', '#ccc').css('background', '#fafafa');
    });

    // --- 5. IBAN კოპირების ლოგიკა (ახალი) ---
    $(document).on('click', '.cx-copy-iban', function() {
        const textToCopy = $(this).text().trim();
        const $element = $(this);
        
        // კოპირება
        navigator.clipboard.writeText(textToCopy).then(function() {
            // ვიზუალური ეფექტი
            const originalBg = $element.css('background-color');
            $element.css('background-color', '#d4edda'); // მწვანე ფერი
            $element.css('color', '#155724');
            
            setTimeout(function() {
                $element.css('background-color', '#eee');
                $element.css('color', '#333');
            }, 300);
        });
    });


    // --- 6. AJAX გაგზავნა ---
    $('.capex-loan-form').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const $btn = $form.find('.btn-submit');
        const originalText = $btn.text();
        $btn.text('იგზავნება...').prop('disabled', true);

        let formData = new FormData(this);
        formData.append('action', 'capex_submit_application');
        formData.append('security', capex_obj.nonce);

        // Replace radio values with their labels
        $form.find('input[type="radio"]:checked').each(function() {
            var name = $(this).attr('name');
            var labelText = $(this).closest('label').text().trim();
            if (labelText) {
                formData.set(name, labelText);
            }
        });

        // Replace select values with their visible text
        $form.find('select').each(function() {
            var name = $(this).attr('name');
            var selectedText = $(this).find('option:selected').text().trim();
            if (selectedText && selectedText !== '- აირჩიეთ -') {
                formData.set(name, selectedText);
            }
        });

        $.ajax({
            url: capex_obj.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    $('.form-step').removeClass('active');
                    $('#step-success').addClass('active');
                    $('.capex-progress').hide();
                    $('#capex-error-box').hide();
                    scrollToTop();
                } else {
                    alert('შეცდომა: ' + (response.data.message || 'უცნობი შეცდომა'));
                    $btn.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                alert('სისტემური შეცდომა.');
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });

    $("<style>")
    .prop("type", "text/css")
    .html(`
    @keyframes shake {
        0% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        50% { transform: translateX(5px); }
        75% { transform: translateX(-5px); }
        100% { transform: translateX(0); }
    }
    .shake-animation { animation: shake 0.3s; }
    .cx-error-border { border-color: #d63638 !important; }
    .cx-error-text { color: #d63638 !important; }
    `)
    .appendTo("head");

});