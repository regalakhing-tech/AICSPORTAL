"""Generate plainly synthetic PDFs for the isolated structured-AI E2E test."""
import argparse
import shutil
from pathlib import Path

try:
    from reportlab.lib.colors import HexColor
    from reportlab.lib.pagesizes import letter
    from reportlab.pdfgen import canvas
except ImportError:
    HexColor = letter = canvas = None


def _document(path: Path, title: str, rows: list[tuple[str, str]], marker: str):
    path.parent.mkdir(parents=True, exist_ok=True)
    if canvas is None:
        # Validation environments do not need ReportLab: Pillow creates the
        # same plainly synthetic, text-based PDF for the production loader.
        from PIL import Image, ImageDraw, ImageFont
        font_path = Path(r'C:\Windows\Fonts\arial.ttf')
        font = lambda size: ImageFont.truetype(str(font_path), size) if font_path.is_file() else ImageFont.load_default()
        image = Image.new('RGB', (1275, 1650), 'white'); draw = ImageDraw.Draw(image)
        draw.rectangle((0, 0, 1275, 115), fill='#B91C1C')
        draw.text((210, 28), 'SAMPLE - FOR SYSTEM TESTING ONLY', fill='white', font=font(28))
        draw.text((70, 160), title, fill='#111827', font=font(35))
        y = 260
        for label, value in rows:
            draw.text((85, y), label.upper(), fill='#4B5563', font=font(19))
            draw.text((410, y), value, fill='#111827', font=font(25))
            y += 95
        draw.text((170, 1570), 'SYNTHETIC TEST DOCUMENT - NOT VALID FOR OFFICIAL USE', fill='#B91C1C', font=font(18))
        image.save(path, 'PDF', resolution=150)
        return
    pdf = canvas.Canvas(str(path), pagesize=letter)
    width, height = letter
    pdf.setFillColor(HexColor('#B91C1C'))
    pdf.rect(0, height - 68, width, 68, fill=1, stroke=0)
    pdf.setFillColorRGB(1, 1, 1)
    pdf.setFont('Helvetica-Bold', 21)
    pdf.drawCentredString(width / 2, height - 30, 'SAMPLE - FOR SYSTEM TESTING ONLY')
    pdf.setFont('Helvetica-Bold', 12)
    pdf.drawCentredString(width / 2, height - 51, marker)
    pdf.setFillColor(HexColor('#111827'))
    pdf.setFont('Helvetica-Bold', 21)
    pdf.drawString(56, height - 112, title)
    y = height - 160
    for label, value in rows:
        pdf.setFillColor(HexColor('#4B5563'))
        pdf.setFont('Helvetica-Bold', 10)
        pdf.drawString(64, y, label.upper())
        pdf.setFillColor(HexColor('#111827'))
        pdf.setFont('Helvetica', 14)
        pdf.drawString(228, y - 1, value)
        pdf.setStrokeColor(HexColor('#E5E7EB'))
        pdf.line(64, y - 13, width - 64, y - 13)
        y -= 58
    pdf.setFillColor(HexColor('#B91C1C'))
    pdf.setFont('Helvetica-Bold', 12)
    pdf.drawCentredString(width / 2, 45, 'SYNTHETIC TEST DOCUMENT - NOT VALID FOR OFFICIAL USE')
    pdf.showPage()
    pdf.save()


def generate(output: Path, medical_year: int, medical_only: bool = False):
    source_id = Path(__file__).resolve().parents[1] / 'output' / 'pdf' / 'sample-classifier-valid-id.pdf'
    output.mkdir(parents=True, exist_ok=True)
    if not medical_only:
        shutil.copyfile(source_id, output / 'valid-id.pdf')
    _document(output / 'medical-certificate.pdf', 'MEDICAL CERTIFICATE', [
        ('Patient Name', 'Juan Dela Cruz'),
        ('Date of Birth', f'January 2, {medical_year}'),
        ('Sex', 'Male'),
        ('Certificate Date', 'August 20, 2026'),
        ('Facility', 'Sample Medical Center'),
        ('Physician', 'Dr. Maria Santos'),
    ], 'NOT A REAL MEDICAL RECORD')
    if not medical_only:
        _document(output / 'billing-statement.pdf', 'BILLING STATEMENT', [
            ('Patient Name', 'Juan Dela Cruz'),
            ('Billing Date', 'August 21, 2026'),
            ('Total Amount', 'PHP 5,000.00'),
            ('Facility', 'Sample Medical Center'),
            ('Charges', 'Synthetic testing services'),
        ], 'NOT A REAL BILL OR INVOICE')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('output', type=Path)
    parser.add_argument('--medical-year', type=int, choices=(1950, 1960), default=1950)
    parser.add_argument('--medical-only', action='store_true')
    args = parser.parse_args()
    generate(args.output.resolve(), args.medical_year, args.medical_only)
    print(args.output.resolve())
