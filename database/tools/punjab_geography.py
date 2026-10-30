"""
Generates database/geography_punjab.sql — the districts, tehsils and standard
field-administration departments of the Province of Punjab.

    python database/tools/punjab_geography.py

The administrative map reflects the position after the 2022-23 reorganisation
(41 districts, including Murree, Talagang, Wazirabad, Taunsa and Kot Addu).
Boundaries change by notification; verify against the latest Board of Revenue
list before relying on it, then edit PUNJAB below and regenerate.

Departments follow the standard structure of a district in Punjab:
  * one district-level authority per district   — Deputy Commissioner Office
  * one tehsil-level authority per tehsil        — Assistant Commissioner Office
  * department-level offices at district HQ and in each tehsil
"""
import io
import os
import re

# division -> district -> (district code, [tehsils])
PUNJAB = {
    'Bahawalpur': {
        'Bahawalpur':     ('BWP', ['Bahawalpur City', 'Bahawalpur Saddar', 'Ahmedpur East', 'Hasilpur', 'Khairpur Tamewali', 'Yazman']),
        'Bahawalnagar':   ('BWN', ['Bahawalnagar', 'Chishtian', 'Fort Abbas', 'Haroonabad', 'Minchinabad']),
        'Rahim Yar Khan': ('RYK', ['Rahim Yar Khan', 'Khanpur', 'Liaquatpur', 'Sadiqabad']),
    },
    'Dera Ghazi Khan': {
        'Dera Ghazi Khan': ('DGK', ['Dera Ghazi Khan', 'Kot Chutta', 'Koh-e-Suleman']),
        'Taunsa':          ('TNS', ['Taunsa', 'Vehova']),
        'Layyah':          ('LYH', ['Layyah', 'Chaubara', 'Karor Lal Esan']),
        'Muzaffargarh':    ('MZG', ['Muzaffargarh', 'Alipur', 'Jatoi']),
        'Kot Addu':        ('KAD', ['Kot Addu', 'Chowk Sarwar Shaheed']),
        'Rajanpur':        ('RJP', ['Rajanpur', 'Jampur', 'Rojhan']),
    },
    'Faisalabad': {
        'Faisalabad':     ('FSD', ['Faisalabad City', 'Faisalabad Saddar', 'Chak Jhumra', 'Jaranwala', 'Samundri', 'Tandlianwala']),
        'Chiniot':        ('CNT', ['Chiniot', 'Bhowana', 'Lalian']),
        'Jhang':          ('JHG', ['Jhang', 'Ahmadpur Sial', 'Athara Hazari', 'Shorkot']),
        'Toba Tek Singh': ('TTS', ['Toba Tek Singh', 'Gojra', 'Kamalia', 'Pir Mahal']),
    },
    'Gujranwala': {
        'Gujranwala': ('GRW', ['Gujranwala City', 'Gujranwala Saddar', 'Kamoke', 'Nowshera Virkan']),
        'Narowal':    ('NRW', ['Narowal', 'Shakargarh', 'Zafarwal']),
        'Sialkot':    ('SKT', ['Sialkot', 'Daska', 'Pasrur', 'Sambrial']),
    },
    'Gujrat': {
        'Gujrat':          ('GJT', ['Gujrat', 'Kharian', 'Sarai Alamgir', 'Jalalpur Jattan']),
        'Mandi Bahauddin': ('MBD', ['Mandi Bahauddin', 'Malakwal', 'Phalia']),
        'Hafizabad':       ('HFD', ['Hafizabad', 'Pindi Bhattian']),
        'Wazirabad':       ('WZD', ['Wazirabad', 'Alipur Chatha']),
    },
    'Lahore': {
        'Lahore':        ('LHR', ['Lahore City', 'Lahore Cantonment', 'Model Town', 'Raiwind', 'Shalimar']),
        'Kasur':         ('KSR', ['Kasur', 'Chunian', 'Kot Radha Kishan', 'Pattoki']),
        'Nankana Sahib': ('NNS', ['Nankana Sahib', 'Shahkot', 'Sangla Hill']),
        'Sheikhupura':   ('SKP', ['Sheikhupura', 'Ferozewala', 'Muridke', 'Sharaqpur', 'Safdarabad']),
    },
    'Multan': {
        'Multan':   ('MLT', ['Multan City', 'Multan Saddar', 'Jalalpur Pirwala', 'Shujabad']),
        'Khanewal': ('KNW', ['Khanewal', 'Jahanian', 'Kabirwala', 'Mian Channu']),
        'Lodhran':  ('LDN', ['Lodhran', 'Dunyapur', 'Kahror Pacca']),
        'Vehari':   ('VHR', ['Vehari', 'Burewala', 'Mailsi']),
    },
    'Rawalpindi': {
        'Rawalpindi': ('RWP', ['Rawalpindi', 'Gujar Khan', 'Kahuta', 'Kallar Syedan', 'Taxila']),
        'Murree':     ('MUR', ['Murree', 'Kotli Sattian']),
        'Attock':     ('ATK', ['Attock', 'Fateh Jang', 'Hassan Abdal', 'Hazro', 'Jand', 'Pindi Gheb']),
        'Chakwal':    ('CKW', ['Chakwal', 'Choa Saidan Shah', 'Kallar Kahar']),
        'Talagang':   ('TLG', ['Talagang', 'Lawa']),
        'Jhelum':     ('JLM', ['Jhelum', 'Dina', 'Pind Dadan Khan', 'Sohawa']),
    },
    'Sahiwal': {
        'Sahiwal':   ('SWL', ['Sahiwal', 'Chichawatni']),
        'Okara':     ('OKR', ['Okara', 'Depalpur', 'Renala Khurd']),
        'Pakpattan': ('PKP', ['Pakpattan', 'Arifwala']),
    },
    'Sargodha': {
        'Sargodha': ('SGD', ['Sargodha', 'Bhalwal', 'Bhera', 'Kot Momin', 'Sahiwal', 'Shahpur', 'Sillanwali']),
        'Bhakkar':  ('BKR', ['Bhakkar', 'Darya Khan', 'Kaloorkot', 'Mankera']),
        'Khushab':  ('KSB', ['Khushab', 'Naushera', 'Noorpur Thal', 'Quaidabad']),
        'Mianwali': ('MWL', ['Mianwali', 'Isakhel', 'Piplan']),
    },
}

# The authority of each district: level 'district'.
DISTRICT_AUTHORITY = ('Deputy Commissioner Office', 'DC', 'Deputy Commissioner')

# Offices at district headquarters: level 'department' (a single authority).
DISTRICT_OFFICES = [
    ('District Education Authority',              'DEA', 'Chief Executive Officer (Education)'),
    ('District Health Authority',                 'DHA', 'Chief Executive Officer (Health)'),
    ('District Accounts Office',                  'DAO', 'District Accounts Officer'),
    ('Agriculture Extension',                     'AGR', 'Deputy Director Agriculture (Extension)'),
    ('Livestock & Dairy Development',             'LDD', 'Deputy District Livestock Officer'),
    ('Social Welfare & Bait-ul-Maal',             'SWB', 'District Social Welfare Officer'),
    ('Population Welfare',                        'PWD', 'District Population Welfare Officer'),
    ('Excise, Taxation & Narcotics Control',      'ETN', 'Excise & Taxation Officer'),
    ('Food Department',                           'FOD', 'District Food Controller'),
    ('Civil Defence',                             'CDF', 'District Civil Defence Officer'),
    ('Rescue 1122',                               'RSQ', 'District Emergency Officer'),
    ('Zakat & Ushr',                              'ZKT', 'District Zakat Officer'),
    ('Sports & Youth Affairs',                    'SPT', 'District Sports Officer'),
    ('Industries, Commerce & Investment',         'IND', 'Assistant Director Industries'),
    ('Information & Culture',                     'INF', 'District Information Officer'),
]

# The authority of each tehsil: level 'tehsil'.
TEHSIL_AUTHORITY = ('Assistant Commissioner Office', 'AC', 'Assistant Commissioner')

# Offices in each tehsil: level 'department'.
TEHSIL_OFFICES = [
    ('Tehsildar Office',     'THD', 'Tehsildar'),
    ('Arazi Record Centre',  'ARC', 'Assistant Director Land Records'),
]

# Known coordinates (the offices this system was piloted with).
COORDS = {
    ('NNS', None, 'DC'):  (31.4492000, 73.7089000, 'District Complex, Nankana Sahib'),
    ('NNS', 'Nankana Sahib', 'AC'): (31.4475000, 73.7051000, 'Tehsil Complex, Nankana Sahib'),
    ('NNS', 'Shahkot', 'AC'):       (31.7167000, 73.9833000, 'Tehsil Complex, Shahkot'),
    ('NNS', 'Sangla Hill', 'AC'):   (31.5606000, 73.8347000, 'Tehsil Complex, Sangla Hill'),
    ('SKP', 'Safdarabad', 'AC'):    (31.3833000, 73.9667000, 'Tehsil Complex, Safdarabad'),
    ('NNS', 'Nankana Sahib', 'ARC'): (31.4460000, 73.7160000, 'Nankana Sahib'),
}


def tehsil_code(name, taken):
    """Readable 3-letter code: initials for multi-word names (Sangla Hill -> SHL),
    first letter + distinct consonants otherwise (Shahkot -> SHK)."""
    words = [w for w in re.split(r'[\s\-]+', name) if w]
    def consonants(word, skip_first):
        out = []
        for c in re.sub(r'[^A-Za-z]', '', word).upper()[1 if skip_first else 0:]:
            if c not in 'AEIOU' and (not out or out[-1] != c):
                out.append(c)
        return out
    if len(words) >= 2:
        base = ''.join(w[0].upper() for w in words[:3])
        for c in consonants(words[-1], True):
            if len(base) >= 3:
                break
            base += c
    else:
        first = words[0][0].upper()
        rest = [c for c in consonants(words[0], True) if c != first]
        base = first + ''.join(rest[:2])
    base = (base + 'XXX')[:3]
    code, n = base, 2
    while code in taken:
        code = base[:2] + str(n)
        n += 1
    taken.add(code)
    return code


def q(v):
    if v is None:
        return 'NULL'
    if isinstance(v, (int, float)):
        return repr(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"


def build():
    districts, tehsils, depts = [], [], []
    all_tehsil_names = {}
    for div in PUNJAB.values():
        for dname, (_, tlist) in div.items():
            for t in tlist:
                all_tehsil_names.setdefault(t, []).append(dname)

    did = tid = pid = 0
    for division in sorted(PUNJAB):
        for dname in sorted(PUNJAB[division]):
            dcode, tlist = PUNJAB[division][dname]
            did += 1
            districts.append((did, division, dname, dcode, did))

            # district authority + district-HQ offices
            name, code, head = DISTRICT_AUTHORITY
            pid += 1
            lat, lng, addr = COORDS.get((dcode, None, code), (None, None, dname))
            depts.append((pid, f'{name} {dname}', f'{dcode}-{code}', 'district', did, None, head, addr, lat, lng, 1))
            for i, (name, code, head) in enumerate(DISTRICT_OFFICES, start=2):
                pid += 1
                depts.append((pid, f'{name} {dname}', f'{dcode}-{code}', 'department', did, None, head, dname, None, None, i))

            taken = set()
            for order, tname in enumerate(tlist, start=1):
                tid += 1
                tcode = tehsil_code(tname, taken)
                tehsils.append((tid, did, tname, tcode, order))
                # a tehsil name used in two districts (e.g. Sahiwal) is qualified
                label = tname if len(all_tehsil_names[tname]) == 1 else f'{tname} ({dname})'

                name, code, head = TEHSIL_AUTHORITY
                pid += 1
                lat, lng, addr = COORDS.get((dcode, tname, code), (None, None, f'Tehsil {tname}, District {dname}'))
                depts.append((pid, f'{name} {label}', f'{dcode}-{tcode}-{code}', 'tehsil', did, tid, head, addr, lat, lng, 1))
                for i, (name, code, head) in enumerate(TEHSIL_OFFICES, start=2):
                    pid += 1
                    lat, lng, addr = COORDS.get((dcode, tname, code), (None, None, f'Tehsil {tname}, District {dname}'))
                    depts.append((pid, f'{name} {label}', f'{dcode}-{tcode}-{code}', 'department', did, tid, head, addr, lat, lng, i))
    return districts, tehsils, depts


def sql():
    districts, tehsils, depts = build()
    names = [d[1] for d in depts]
    assert len(names) == len(set(names)), 'duplicate department name'
    codes = [d[2] for d in depts]
    assert len(codes) == len(set(codes)), 'duplicate department code'

    out = io.StringIO()
    w = out.write
    w('-- Generated by database/tools/punjab_geography.py — edit the generator, not this file.\n')
    w(f'-- Province of Punjab: {len(districts)} districts, {len(tehsils)} tehsils, {len(depts)} departments.\n\n')

    w('INSERT INTO districts (id, division, name, code, sort_order) VALUES\n')
    w(',\n'.join(' (' + ', '.join(q(v) for v in r) + ')' for r in districts) + ';\n\n')

    w('INSERT INTO tehsils (id, district_id, name, code, sort_order) VALUES\n')
    w(',\n'.join(' (' + ', '.join(q(v) for v in r) + ')' for r in tehsils) + ';\n\n')

    w('INSERT INTO departments (id, name, code, level, district_id, tehsil_id, head_designation, address, latitude, longitude, sort_order) VALUES\n')
    w(',\n'.join(' (' + ', '.join(q(v) for v in r) + ')' for r in depts) + ';\n')
    return out.getvalue(), len(districts), len(tehsils), len(depts)


if __name__ == '__main__':
    here = os.path.dirname(os.path.abspath(__file__))
    text, nd, nt, np = sql()
    target = os.path.join(here, '..', 'geography_punjab.sql')
    with io.open(target, 'w', encoding='utf-8', newline='\n') as f:
        f.write(text)
    print(f'wrote {os.path.normpath(target)}: {nd} districts, {nt} tehsils, {np} departments')
