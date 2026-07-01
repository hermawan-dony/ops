import os
import zipfile
import shutil

extract_dir = 'ops_https'
zip_path = 'ops.aia'

if os.path.exists(extract_dir):
    shutil.rmtree(extract_dir)

os.makedirs(extract_dir)

# 1. Unzip ops.aia
with zipfile.ZipFile(zip_path, 'r') as zip_ref:
    zip_ref.extractall(extract_dir)

# 2. Find and Modify SCM & BKY recursively
scm_path = None
bky_path = None

for root, dirs, files in os.walk(os.path.join(extract_dir, 'src')):
    for file in files:
        if file.endswith('.scm'):
            scm_path = os.path.join(root, file)
        elif file.endswith('.bky'):
            bky_path = os.path.join(root, file)

if scm_path:
    with open(scm_path, 'r', encoding='utf-8') as f:
        content = f.read()
    # Replace http with https
    content = content.replace('http:\\/\\/ops.framas.co.id\\/', 'https:\\/\\/ops.framas.co.id\\/')
    with open(scm_path, 'w', encoding='utf-8', newline='\n') as f:
        f.write(content)
    print("Modified SCM to HTTPS")

if bky_path:
    with open(bky_path, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace('http://ops.framas.co.id/', 'https://ops.framas.co.id/')
    with open(bky_path, 'wb') as f:
        f.write(content.encode('utf-8'))
    print("Modified BKY to HTTPS")

# 3. Pack everything back to ops.aia
if os.path.exists(zip_path):
    os.remove(zip_path)

with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
    for root, dirs, files in os.walk(extract_dir):
        for file in files:
            full_path = os.path.join(root, file)
            rel_path = os.path.relpath(full_path, extract_dir).replace('\\\\', '/').replace('\\', '/')
            zipf.write(full_path, rel_path)

shutil.rmtree(extract_dir)
print("SUCCESS: Packed HTTPS version to ops.aia")
