"""A one-page PDF for the Printers page's "Print test page" button.

Built by hand (a PDF this small needs no library) so the device image needs no
extra package. It is sent with print-scaling=auto, so CUPS fits it to whatever
media the queue is set to — letter paper or a label — rather than the exact
size the website renders. A border near the page edge shows whether the media
size is right; the text shows which queue and device it came from.
"""
from datetime import datetime


def _escape(text: str) -> str:
    return text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)").encode("latin-1", "replace").decode("latin-1")


def build(queue: str, device: str, when: datetime) -> bytes:
    width, height = 612, 792
    lines = [
        (28, "R Station test page"),
        (16, f"Printer: {queue}"),
        (16, f"From: {device}"),
        (12, when.strftime("%Y-%m-%d %H:%M")),
        (12, "If you can read this and the border is complete on all four sides,"),
        (12, "the printer and its media size are set up correctly."),
    ]
    content = [f"2 w 24 24 {width - 48} {height - 48} re S"]
    y = height - 110
    for size, text in lines:
        content.append(f"BT /F1 {size} Tf 56 {y} Td ({_escape(text)}) Tj ET")
        y -= size + 14
    stream = "\n".join(content).encode("latin-1")
    objects = [
        b"<</Type/Catalog/Pages 2 0 R>>",
        b"<</Type/Pages/Kids[3 0 R]/Count 1>>",
        f"<</Type/Page/Parent 2 0 R/MediaBox[0 0 {width} {height}]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>".encode(),
        b"<</Length %d>>\nstream\n" % len(stream) + stream + b"\nendstream",
        b"<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>",
    ]
    out = bytearray(b"%PDF-1.4\n")
    offsets = []
    for number, body in enumerate(objects, 1):
        offsets.append(len(out))
        out += f"{number} 0 obj\n".encode() + body + b"\nendobj\n"
    xref = len(out)
    out += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode()
    for offset in offsets:
        out += f"{offset:010d} 00000 n \n".encode()
    out += f"trailer\n<</Size {len(objects) + 1}/Root 1 0 R>>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(out)
