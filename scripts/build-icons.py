"""Regenerate the checked-in PWA icons; not required on the PHP server."""
from pathlib import Path
import sys
from PIL import Image, ImageDraw, ImageFont

root = Path(__file__).resolve().parents[1]
font_path = Path(sys.argv[1]) if len(sys.argv) > 1 else Path('C:/Windows/Fonts/msyh.ttc')
if not font_path.is_file():
    raise SystemExit('Pass a font file supporting Chinese: python scripts/build-icons.py /path/to/font.ttf')
for size, name in [(192, 'icon-192.png'), (512, 'icon-512.png'), (180, 'apple-touch-icon.png')]:
    canvas = Image.new('RGB', (size, size), '#426e55')
    draw = ImageDraw.Draw(canvas)
    font = ImageFont.truetype(str(font_path), round(size * .50))
    bounds = draw.textbbox((0, 0), '月', font=font)
    x = (size - bounds[2] + bounds[0]) / 2 - bounds[0]
    y = (size - bounds[3] + bounds[1]) / 2 - bounds[1]
    draw.text((x, y), '月', font=font, fill='#fffdf8')
    canvas.save(root / 'public' / 'assets' / name)
print('Generated 192px, 512px and Apple 180px icons.')
