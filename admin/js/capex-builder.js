jQuery(document).ready(function($) {

    const $builder = $('#capex-builder-app');
    const $textarea = $('#capex_form_structure');
    
    let initialData = [];
    if (typeof capex_builder_data !== 'undefined' && capex_builder_data.structure) {
        initialData = capex_builder_data.structure;
    }

    const fieldTypes = {
        'text': 'ტექსტი (Text)',
        'number': 'რიცხვი (Number)',
        'email': 'ელ-ფოსტა (Email)',
        'date': 'თარიღი (Date)',
        'file': 'ფაილის ატვირთვა',
        'radio': 'რადიო ღილაკები (Radio)',
        'select': 'ჩამოსაშლელი სია (Select)',
        'html': 'HTML / Consent Text'
    };

    const widthOptions = {
        '100': '1/1 - მთლიანი ხაზი',
        '50':  '1/2 - ნახევარი',
        '33':  '1/3 - მესამედი',
        '25':  '1/4 - მეოთხედი'
    };

    const ssoOptions = {
        '': '— არაფერი —',
        'name': 'სახელი (Name)',
        'surname': 'გვარი (Surname)',
        'pid': 'პირადი ნომერი (ID)',
        'phone': 'ტელეფონი',
        'email': 'ელ-ფოსტა',
        'address': 'მისამართი',
        'dob': 'დაბადების თარიღი'
    };

    initBuilder();

    function initBuilder() {
        $builder.html('');
        $builder.append('<div style="margin-bottom:15px;"><a href="#" class="cx-btn cx-btn-primary" id="add-step">+ ნაბიჯის დამატება</a></div>');
        $builder.append('<div id="cx-steps-container"></div>');

        if (Array.isArray(initialData) && initialData.length > 0) {
            $.each(initialData, function(index, step) {
                renderStep(step);
            });
        } else {
            renderStep();
        }

        initSortable();
        refreshAllLogicSelects();
        updateJSON();
    }

    function renderStep(stepData = {}) {
        const fields = stepData.fields || [];
        
        const stepHtml = `
            <div class="cx-step">
                <div class="cx-step-header">
                    <span class="cx-step-title">ნაბიჯი</span>
                    <div>
                        <a href="#" class="cx-btn add-field-btn">+ ველი</a>
                        <a href="#" class="cx-btn cx-btn-danger remove-step-btn" style="margin-left:5px;">×</a>
                    </div>
                </div>
                <div class="cx-step-body ui-sortable"></div>
            </div>
        `;

        const $step = $(stepHtml);
        $('#cx-steps-container').append($step);

        if (fields.length > 0) {
            $.each(fields, function(i, field) {
                renderField($step.find('.cx-step-body'), field);
            });
        }
    }

    function renderField($container, fieldData = {}) {
        const id = fieldData.id || 'field_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
        const label = fieldData.label || 'ახალი ველი';
        const type = fieldData.type || 'text';
        const width = fieldData.width || '100';
        const required = fieldData.required ? 'checked' : '';
        const ssoMap = fieldData.sso_map || '';
        const htmlContent = fieldData.html_content || '';
        
        // HTML პარამეტრები
        const htmlCheckboxLabel = fieldData.html_checkbox_label || 'გავეცანი და ვეთანხმები';
        const htmlAutoHeight = fieldData.html_auto_height ? 'checked' : '';

        // Text პარამეტრები (ახალი)
        const maxLength = fieldData.max_length || '';
        const numbersOnly = fieldData.numbers_only ? 'checked' : '';

        const options = fieldData.options || [];
        const logic = fieldData.logic || { enabled: false, action: 'show', field: '', operator: 'equals', value: '' };

        // Options Generator
        let optionsHtml = '';
        if(options.length > 0) {
            options.forEach(opt => {
                optionsHtml += getOptionRowHtml(opt.label, opt.value);
            });
        } else {
            if(type === 'radio' || type === 'select') {
                optionsHtml += getOptionRowHtml('ოფცია 1', 'option_1');
                optionsHtml += getOptionRowHtml('ოფცია 2', 'option_2');
            }
        }

        const typeOptions = generateOptions(fieldTypes, type);
        const widthSelectOptions = generateOptions(widthOptions, width);
        const ssoSelectOptions = generateOptions(ssoOptions, ssoMap);

        const showOptions = (type === 'radio' || type === 'select') ? 'block' : 'none';
        const showHtml = (type === 'html') ? 'block' : 'none';
        const showTextSettings = (type === 'text') ? 'block' : 'none'; // ახალი
        const showLogic = logic.enabled ? 'block' : 'none';
        const logicChecked = logic.enabled ? 'checked' : '';
        const savedLogicField = logic.field || '';

        const fieldHtml = `
            <div class="cx-field" data-id="${id}">
                <div class="cx-field-header">
                    <span class="cx-field-handle dashicons dashicons-menu"></span>
                    <span class="cx-field-preview">${label}</span>
                    <span class="cx-field-type-badge">${type}</span>
                    <span class="cx-field-type-badge" style="background:#dceeff;">${widthOptions[width]}</span>
                    <div class="cx-field-actions">
                        <a href="#" class="cx-btn toggle-field-btn">Edit</a>
                        <a href="#" class="cx-btn cx-btn-danger remove-field-btn">×</a>
                    </div>
                </div>
                <div class="cx-field-settings">
                    <div class="cx-form-row">
                        <label>ველის სათაური (Label)</label>
                        <input type="text" class="cx-input-label" value="${label.replace(/"/g, '&quot;')}">
                    </div>
                    
                    <div style="display:flex; gap:15px;">
                        <div class="cx-form-row" style="flex:1;">
                            <label>ტიპი</label>
                            <select class="cx-input-type">${typeOptions}</select>
                        </div>
                        <div class="cx-form-row" style="flex:1;">
                            <label>ზომა (სიგანე)</label>
                            <select class="cx-input-width">${widthSelectOptions}</select>
                        </div>
                    </div>

                    <div class="cx-options-wrapper" style="display:${showOptions};">
                        <label style="font-weight:bold; font-size:12px;">არჩევანის ოფციები:</label>
                        <div class="cx-options-list">${optionsHtml}</div>
                        <button class="cx-btn cx-btn-sm add-option-btn" style="margin-top:5px;">+ Add Option</button>
                    </div>

                    <div class="cx-form-row" style="background:#f0f7ff; padding:10px; border:1px solid #cce5ff; margin-top:10px;">
                        <label style="color:#0073aa;">🔗 MyCreditinfo Mapping (SSO) & Autofill</label>
                        <select class="cx-input-sso">${ssoSelectOptions}</select>
                    </div>
                    
                    <div class="cx-form-row" style="margin-top:10px;">
                        <label><input type="checkbox" class="cx-input-required" ${required}> სავალდებულო (Required)</label>
                    </div>

                    <div class="text-settings-group" style="display:${showTextSettings}; border-left:3px solid #0073aa; padding-left:10px; margin-top:10px;">
                        <div class="cx-form-row">
                             <label>სიმბოლოების ლიმიტი (Max Length)</label>
                             <input type="number" class="cx-input-max-length" value="${maxLength}" placeholder="მაგ: 10">
                        </div>
                        <div class="cx-form-row">
                             <label><input type="checkbox" class="cx-input-numbers-only" ${numbersOnly}> მხოლოდ ციფრები (0-9)</label>
                        </div>
                    </div>

                    <div class="html-settings-group" style="display:${showHtml}; border-left:3px solid #0073aa; padding-left:10px; margin-top:10px;">
                        <div class="cx-form-row">
                            <label>HTML შიგთავსი (Consent Text)</label>
                            <textarea class="cx-input-html" rows="5" style="width:100%">${htmlContent}</textarea>
                            <p style="font-size:11px; color:#888;">გთხოვთ გამოიყენოთ <b>ცალმაგი ბრჭყალები</b> (') HTML ატრიბუტებისთვის.</p>
                        </div>
                        <div class="cx-form-row">
                             <label>მოსანიშნი ტექსტი (Checkbox Label)</label>
                             <input type="text" class="cx-input-html-label" value="${htmlCheckboxLabel.replace(/"/g, '&quot;')}" placeholder="გავეცანი და ვეთანხმები">
                        </div>
                        <div class="cx-form-row">
                             <label><input type="checkbox" class="cx-input-html-auto" ${htmlAutoHeight}> სქროლის გათიშვა (Auto Height)</label>
                             <p style="font-size:11px; color:#888;">მონიშვნის შემთხვევაში ტექსტი გამოჩნდება სრულად, სქროლის გარეშე.</p>
                        </div>
                    </div>

                    <div class="cx-logic-wrapper">
                        <div class="cx-logic-header">
                            <label><input type="checkbox" class="cx-logic-enable" ${logicChecked}> პირობითი ლოგიკა (Conditional Logic)</label>
                        </div>
                        <div class="cx-logic-rules" style="display:${showLogic};">
                            <div class="cx-logic-row">
                                <span>გამოჩნდეს თუ: </span>
                                <select class="cx-logic-field" data-saved-value="${savedLogicField}">
                                    <option value="">- აირჩიეთ ველი -</option>
                                </select>
                                <select class="cx-logic-operator">
                                    <option value="equals" ${logic.operator==='equals'?'selected':''}>უდრის</option>
                                    <option value="not_equals" ${logic.operator==='not_equals'?'selected':''}>არ უდრის</option>
                                    <option value="empty" ${logic.operator==='empty'?'selected':''}>ცარიელია</option>
                                    <option value="not_empty" ${logic.operator==='not_empty'?'selected':''}>არ არის ცარიელი</option>
                                </select>
                                <input type="text" class="cx-logic-value" value="${logic.value}" placeholder="მნიშვნელობა" style="width:100px;">
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        `;
        $container.append(fieldHtml);
    }

    // --- HELPERS ---

    function getOptionRowHtml(label = '', value = '') {
        return `
            <div class="cx-option-row">
                <input type="text" class="cx-opt-label" placeholder="Label" value="${label}">
                <input type="text" class="cx-opt-value" placeholder="Value" value="${value}">
                <div class="cx-option-actions">
                    <a href="#" class="cx-btn cx-btn-danger cx-btn-sm remove-option-btn">×</a>
                </div>
            </div>
        `;
    }

    function generateOptions(optionsObj, selectedVal) {
        let html = '';
        for (let key in optionsObj) {
            let selected = (key == selectedVal) ? 'selected' : '';
            html += `<option value="${key}" ${selected}>${optionsObj[key]}</option>`;
        }
        return html;
    }

    function refreshAllLogicSelects() {
        let allFields = [];
        $('.cx-field').each(function() {
            const id = $(this).data('id');
            const label = $(this).find('.cx-input-label').val() || 'Unnamed Field';
            allFields.push({ id: id, label: label });
        });

        $('.cx-field').each(function() {
            const currentId = $(this).data('id');
            const $select = $(this).find('.cx-logic-field');
            const savedVal = $select.attr('data-saved-value') || '';
            const currentUiVal = $select.val(); 
            const valToSet = currentUiVal || savedVal;

            $select.empty().append('<option value="">- აირჩიეთ ველი -</option>');
            allFields.forEach(f => {
                if(f.id !== currentId) {
                    $select.append(`<option value="${f.id}">${f.label}</option>`);
                }
            });
            $select.val(valToSet);
        });
    }

    // --- EVENTS ---

    $(document).on('click', '#add-step', function(e) {
        e.preventDefault();
        renderStep();
        initSortable();
        refreshAllLogicSelects();
        updateJSON();
    });

    $(document).on('click', '.remove-step-btn', function(e) {
        e.preventDefault();
        if(confirm('წავშალოთ ნაბიჯი?')) {
            $(this).closest('.cx-step').remove();
            refreshAllLogicSelects();
            updateJSON();
        }
    });

    $(document).on('click', '.add-field-btn', function(e) {
        e.preventDefault();
        renderField($(this).closest('.cx-step').find('.cx-step-body'));
        refreshAllLogicSelects();
        updateJSON();
    });

    $(document).on('click', '.remove-field-btn', function(e) {
        e.preventDefault();
        $(this).closest('.cx-field').remove();
        refreshAllLogicSelects();
        updateJSON();
    });

    $(document).on('click', '.toggle-field-btn', function(e) {
        e.preventDefault();
        $(this).closest('.cx-field').toggleClass('open');
    });

    $(document).on('click', '.add-option-btn', function(e) {
        e.preventDefault();
        $(this).closest('.cx-options-wrapper').find('.cx-options-list').append(getOptionRowHtml());
        updateJSON();
    });

    $(document).on('click', '.remove-option-btn', function(e) {
        e.preventDefault();
        $(this).closest('.cx-option-row').remove();
        updateJSON();
    });

    $(document).on('change', '.cx-logic-enable', function() {
        const $rules = $(this).closest('.cx-logic-wrapper').find('.cx-logic-rules');
        if($(this).is(':checked')) {
            $rules.slideDown();
        } else {
            $rules.slideUp();
        }
        updateJSON();
    });

    $(document).on('change', '.cx-logic-field', function() {
        $(this).attr('data-saved-value', $(this).val());
        updateJSON();
    });

    $(document).on('input change', 'input, select, textarea', function() {
        const $field = $(this).closest('.cx-field');
        
        if($(this).hasClass('cx-input-label')) {
            $field.find('.cx-field-preview').text($(this).val());
        }
        
        if($(this).hasClass('cx-input-type')) {
            const val = $(this).val();
            $field.find('.cx-field-type-badge:first').text(val);
            
            // HTML Settings
            if(val === 'html') $field.find('.html-settings-group').show();
            else $field.find('.html-settings-group').hide();

            // Text Settings
            if(val === 'text') $field.find('.text-settings-group').show();
            else $field.find('.text-settings-group').hide();

            // Options
            if(val === 'radio' || val === 'select') {
                $field.find('.cx-options-wrapper').show();
                if($field.find('.cx-option-row').length === 0) {
                    $field.find('.cx-options-list').append(getOptionRowHtml('Op1', '1') + getOptionRowHtml('Op2', '2'));
                }
            } else {
                $field.find('.cx-options-wrapper').hide();
            }
        }
        updateJSON();
    });

    $(document).on('blur', '.cx-input-label', function() {
        refreshAllLogicSelects();
    });

    $('form#post').on('submit', function() {
        updateJSON();
    });

    function initSortable() {
        $('.cx-step-body').sortable({
            handle: '.cx-field-header',
            connectWith: '.cx-step-body',
            update: function() { 
                updateJSON(); 
            }
        });
    }

    function updateJSON() {
        let structure = [];
        $('.cx-step').each(function() {
            let step = { fields: [] };
            $(this).find('.cx-field').each(function() {
                let $f = $(this);
                let rawHtml = $f.find('.cx-input-html').val() || '';
                let safeHtml = rawHtml.replace(/"/g, "'");

                let options = [];
                $f.find('.cx-option-row').each(function() {
                    options.push({
                        label: $(this).find('.cx-opt-label').val(),
                        value: $(this).find('.cx-opt-value').val()
                    });
                });

                let logic = {
                    enabled: $f.find('.cx-logic-enable').is(':checked'),
                    action: 'show',
                    field: $f.find('.cx-logic-field').val(),
                    operator: $f.find('.cx-logic-operator').val(),
                    value: $f.find('.cx-logic-value').val()
                };

                let field = {
                    id: $f.data('id'),
                    label: $f.find('.cx-input-label').val(),
                    type: $f.find('.cx-input-type').val(),
                    width: $f.find('.cx-input-width').val(),
                    sso_map: $f.find('.cx-input-sso').val(),
                    required: $f.find('.cx-input-required').is(':checked'),
                    html_content: safeHtml,
                    
                    html_checkbox_label: $f.find('.cx-input-html-label').val(),
                    html_auto_height: $f.find('.cx-input-html-auto').is(':checked'),
                    
                    // ახალი Text პარამეტრები
                    max_length: $f.find('.cx-input-max-length').val(),
                    numbers_only: $f.find('.cx-input-numbers-only').is(':checked'),

                    options: options,
                    logic: logic
                };
                step.fields.push(field);
            });
            structure.push(step);
        });
        
        const jsonString = JSON.stringify(structure);
        $textarea.val(jsonString);
    }

});