#!/usr/bin/env python3
"""Convert Gravity Forms export to Capex plugin import files."""

import json
import os
import time
import random

INPUT = 'gravityforms-export-2026-03-10.json'
OUTPUT_DIR = 'migration-imports'

def make_id():
    """Generate timestamp-based field ID matching the plugin's format."""
    ts = int(time.time() * 1000)
    rnd = random.randint(100, 999)
    return f"field_{ts}_{rnd}"

def clean_html(text):
    """Clean HTML content for safe JSON embedding.
    Replace double quotes with single quotes (matching builder JS behavior).
    Replace raw newlines with spaces.
    """
    if not text:
        return ""
    # Replace newlines with space
    text = text.replace('\n', ' ').replace('\r', ' ')
    # Replace double quotes with single quotes (builder does this)
    text = text.replace('"', "'")
    # Replace non-breaking spaces
    text = text.replace('\xa0', ' ')
    # Collapse multiple spaces
    while '  ' in text:
        text = text.replace('  ', ' ')
    return text.strip()

def empty_field(field_id, label, ftype, width="100"):
    """Create a field dict with ALL required properties."""
    return {
        "id": field_id,
        "label": label,
        "type": ftype,
        "width": width,
        "sso_map": "",
        "required": False,
        "html_content": "",
        "html_checkbox_label": "გავეცანი და ვეთანხმები",
        "html_auto_height": False,
        "max_length": "",
        "numbers_only": False,
        "options": [],
        "logic": {
            "enabled": False,
            "action": "show",
            "field": "",
            "operator": "equals",
            "value": ""
        }
    }

def convert_form(gf_form):
    """Convert a single GF form to Capex format."""
    title = gf_form.get('title', 'Unnamed Form')
    gf_fields = gf_form.get('fields', [])

    # We need to map GF field IDs to generated Capex field IDs for conditional logic
    # The radio field (id=50) is the one referenced by all conditional logic
    gf_id_to_capex_id = {}

    # First pass: generate Capex IDs for all fields and split name/address
    capex_fields_by_step = [[]]  # Start with step 0
    current_step = 0

    field_plans = []  # List of (gf_field, plan_type) to process

    for gf_field in gf_fields:
        ftype = gf_field.get('type', '')
        fid = gf_field.get('id', 0)

        if ftype == 'page':
            field_plans.append((gf_field, 'page_break'))
            continue

        if ftype == 'post_custom_field':
            # Skip internal timestamps
            continue

        if ftype == 'name':
            # Split into first/last name fields
            field_plans.append((gf_field, 'name_first'))
            field_plans.append((gf_field, 'name_last'))
            continue

        if ftype == 'address':
            # Split into city/state fields (only visible inputs)
            field_plans.append((gf_field, 'address_city'))
            field_plans.append((gf_field, 'address_state'))
            continue

        field_plans.append((gf_field, 'direct'))

    # Second pass: generate IDs and build fields
    time.sleep(0.01)  # Ensure unique timestamps

    for plan_gf_field, plan_type in field_plans:
        ftype = plan_gf_field.get('type', '')
        fid = plan_gf_field.get('id', 0)
        label = plan_gf_field.get('label', '')

        if plan_type == 'page_break':
            capex_fields_by_step.append([])
            current_step += 1
            continue

        time.sleep(0.002)  # Ensure unique IDs
        capex_id = make_id()

        if plan_type == 'name_first':
            gf_id_to_capex_id[f"{fid}_first"] = capex_id
            f = empty_field(capex_id, "სახელი", "text", "50")
            f["required"] = True
            f["sso_map"] = "name"
            capex_fields_by_step[current_step].append(f)
            continue

        if plan_type == 'name_last':
            gf_id_to_capex_id[f"{fid}_last"] = capex_id
            f = empty_field(capex_id, "გვარი", "text", "50")
            f["required"] = True
            f["sso_map"] = "surname"
            capex_fields_by_step[current_step].append(f)
            continue

        if plan_type == 'address_city':
            gf_id_to_capex_id[f"{fid}_city"] = capex_id
            f = empty_field(capex_id, "ქალაქი", "text", "50")
            f["sso_map"] = "address"
            capex_fields_by_step[current_step].append(f)
            continue

        if plan_type == 'address_state':
            gf_id_to_capex_id[f"{fid}_state"] = capex_id
            f = empty_field(capex_id, "რეგიონი", "text", "50")
            capex_fields_by_step[current_step].append(f)
            continue

        # Direct field mapping
        gf_id_to_capex_id[str(fid)] = capex_id

        if ftype == 'radio':
            choices = plan_gf_field.get('choices', [])
            f = empty_field(capex_id, label, "radio", "100")
            f["required"] = True
            f["options"] = []
            for i, choice in enumerate(choices, 1):
                f["options"].append({
                    "label": choice.get("text", ""),
                    "value": str(i)
                })
            capex_fields_by_step[current_step].append(f)

        elif ftype == 'text':
            f = empty_field(capex_id, label, "text", "100")
            f["required"] = bool(plan_gf_field.get('isRequired', False))
            # PID fields
            if 'პირადი ნომერი' in label:
                f["sso_map"] = "pid"
                f["max_length"] = "11"
                f["numbers_only"] = True
            elif 'საიდენტიფიკაციო კოდი' in label:
                f["sso_map"] = "pid"
                f["max_length"] = "11"
                f["numbers_only"] = True
            capex_fields_by_step[current_step].append(f)

        elif ftype == 'number':
            f = empty_field(capex_id, label, "number", "50")
            f["required"] = bool(plan_gf_field.get('isRequired', False))
            capex_fields_by_step[current_step].append(f)

        elif ftype == 'email':
            f = empty_field(capex_id, label, "email", "100")
            f["required"] = bool(plan_gf_field.get('isRequired', False))
            f["sso_map"] = "email"
            capex_fields_by_step[current_step].append(f)

        elif ftype == 'date':
            f = empty_field(capex_id, label, "date", "100")
            f["required"] = bool(plan_gf_field.get('isRequired', False))
            f["sso_map"] = "dob"
            capex_fields_by_step[current_step].append(f)

        elif ftype == 'fileupload':
            f = empty_field(capex_id, label, "file", "100")
            f["required"] = bool(plan_gf_field.get('isRequired', False))
            capex_fields_by_step[current_step].append(f)

        elif ftype == 'consent':
            desc = plan_gf_field.get('description', '')
            checkbox_label = plan_gf_field.get('checkboxLabel', 'გავეცანი და ვეთანხმები')
            f = empty_field(capex_id, label, "html", "100")
            f["required"] = True
            f["html_content"] = clean_html(desc)
            f["html_checkbox_label"] = checkbox_label if checkbox_label else "გავეცანი და ვეთანხმები"
            f["html_auto_height"] = False
            capex_fields_by_step[current_step].append(f)

        elif ftype == 'gf-free-sms-verification':
            f = empty_field(capex_id, label, "text", "100")
            f["required"] = True
            f["sso_map"] = "phone"
            capex_fields_by_step[current_step].append(f)

        else:
            # Unknown type - skip
            print(f"  Skipping unknown type: {ftype} (id={fid}, label={label})")
            continue

    # Third pass: apply conditional logic
    # GF radio field 50 choices: "ფიზიკური პირი" → value "1", "იურიდიული პირი" → value "2"
    value_map = {
        "ფიზიკური პირი": "1",
        "იურიდიული პირი": "2"
    }
    operator_map = {
        "is": "equals",
        "isnot": "not_equals"
    }

    radio_capex_id = gf_id_to_capex_id.get("50", "")

    for gf_field in gf_fields:
        ftype = gf_field.get('type', '')
        fid = gf_field.get('id', 0)
        cond = gf_field.get('conditionalLogic')

        if not cond or not cond.get('enabled'):
            continue

        # Find the capex field for this GF field
        capex_id = gf_id_to_capex_id.get(str(fid))
        if not capex_id:
            continue

        # Find the capex field in our steps
        for step_fields in capex_fields_by_step:
            for capex_field in step_fields:
                if capex_field["id"] == capex_id:
                    rules = cond.get('rules', [])
                    if rules:
                        rule = rules[0]
                        gf_ref_id = str(rule.get('fieldId', ''))
                        gf_operator = rule.get('operator', 'is')
                        gf_value = rule.get('value', '')

                        ref_capex_id = gf_id_to_capex_id.get(gf_ref_id, "")
                        capex_operator = operator_map.get(gf_operator, "equals")
                        capex_value = value_map.get(gf_value, gf_value)

                        capex_field["logic"] = {
                            "enabled": True,
                            "action": "show",
                            "field": ref_capex_id,
                            "operator": capex_operator,
                            "value": capex_value
                        }

    # Build structure
    structure = []
    for step_fields in capex_fields_by_step:
        structure.append({"fields": step_fields})

    return {
        "capex_form_export": True,
        "version": "2.3.0",
        "exported_at": "2026-03-10T12:00:00Z",
        "form": {
            "title": title,
            "status": "publish",
            "structure": structure
        }
    }


# Main
with open(INPUT, 'r', encoding='utf-8') as f:
    data = json.load(f)

os.makedirs(OUTPUT_DIR, exist_ok=True)

form_keys = ['0', '1', '2', '3', '4', '5']
for key in form_keys:
    gf_form = data[key]
    title = gf_form.get('title', f'Form {key}')
    print(f"\nConverting: {title}")

    result = convert_form(gf_form)

    # Count fields
    total_fields = sum(len(step['fields']) for step in result['form']['structure'])
    total_steps = len(result['form']['structure'])
    print(f"  Steps: {total_steps}, Fields: {total_fields}")

    # Sanitize filename
    safe_name = title.replace(' ', '-').replace('/', '-')
    filename = f"{safe_name}.json"
    filepath = os.path.join(OUTPUT_DIR, filename)

    with open(filepath, 'w', encoding='utf-8') as f:
        json.dump(result, f, ensure_ascii=False, indent=2)

    # Verify the file is valid JSON and can be re-parsed
    with open(filepath, 'r', encoding='utf-8') as f:
        verify = json.load(f)
    verify_fields = sum(len(s['fields']) for s in verify['form']['structure'])
    print(f"  Verified: {verify_fields} fields in {filepath}")

print(f"\nDone! Files in {OUTPUT_DIR}/")
