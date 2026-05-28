import os
import zipfile

zip_path = 'pelican-mod-manager.zip'
exclude_dirs = {'.git', '.github', 'scratch'}
exclude_files = {zip_path, 'build.py'}

print("Compressing Pelican Mod Manager plugin...")

# Create the zip archive with files at the root (or under a top-level folder if preferred)
with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
    # We walk the current directory
    for root, dirs, files in os.walk('.'):
        # Exclude directories we don't want to pack
        dirs[:] = [d for d in dirs if d not in exclude_dirs]
        
        for file in files:
            if file in exclude_files:
                continue
                
            file_path = os.path.join(root, file)
            # Create standard relative path from root
            arcname = os.path.relpath(file_path, '.')
            
            # Pelican loader expects the root folder inside the zip to be 'minecraft-modrinth' or matching plugin name.
            # To be safe and compatible with standard Pelican plugin installer zips, 
            # we pack the files under a folder matching the namespace/plugin directory structure: 'pelican-mod-manager/'
            arcname_packed = os.path.join('pelican-mod-manager', arcname)
            
            # Force forward slashes for Linux server compatibility
            arcname_packed = arcname_packed.replace('\\', '/')
            zipf.write(file_path, arcname_packed)

print(f"Success! Rebuilt release archive: {zip_path}")
