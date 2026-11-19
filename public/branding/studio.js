(() => {
    const by=id=>document.getElementById(id);
    const canvas=by('brand-card'), ctx=canvas.getContext('2d');
    const label=by('brand-label'),dest=by('destination'),print=by('print-card'),save=by('save-card');
    by('branch-picker').onchange=e=>location.assign(e.target.value);
    function updateLabel(){document.querySelectorAll('[data-label]').forEach(el=>el.textContent=label.value.trim()||label.placeholder);}
    label.oninput=updateLabel;updateLabel();
    let preview,version=0;
    by('brand-file').onchange=e=>{
        if(preview)URL.revokeObjectURL(preview);
        const file=e.target.files[0];preview=file?URL.createObjectURL(file):null;
        document.querySelectorAll('img[data-icon]').forEach(el=>{el.dataset.original??=el.src;el.src=preview||el.dataset.original;});
    };
    function image(url){return new Promise((resolve,reject)=>{const i=new Image();i.onload=()=>resolve(i);i.onerror=reject;i.src=url;});}
    const scan = by('scan-url');
    by('copy-scan').onclick = async () => {
        try {
            await navigator.clipboard.writeText(scan.href);
            by('scan-feedback').textContent = 'Link copied.';
        } catch {
            by('scan-feedback').textContent = 'Touch and hold the link to copy it.';
        }
    };
    async function draw(){
        const id=++version,option=dest.selectedOptions[0];
        scan.href = option.dataset.url;
        scan.textContent = scan.href;
        by('scan-feedback').textContent = '';
        print.disabled=save.disabled=true; by('card-status').textContent='Loading QR card…';
        try{
            const [qr,icon]=await Promise.all([image(option.dataset.qr),image(canvas.dataset.icon)]);
            if(id!==version)return;
            ctx.fillStyle='#fffef9';ctx.fillRect(0,0,900,1260);
            ctx.drawImage(icon,395,65,110,110);ctx.textAlign='center';
            function line(value,y,size,color='#262822',weight=600){ctx.fillStyle=color;ctx.font=weight+' '+size+'px system-ui';ctx.fillText(value,450,y,810);}
            line(canvas.dataset.business,235,36,'#ad4216');line(canvas.dataset.name,322,64);
            line(option.text,396,36,'#ad4216');line('Scan to order',478,48);
            ctx.fillStyle='white';ctx.fillRect(190,503,520,520);
            ctx.imageSmoothingEnabled=false;ctx.drawImage(qr,195,508,510,510);ctx.imageSmoothingEnabled=true;
            line(scan.href,1057,22);
            line('Save the menu to your phone',1100,30);
            line('iPhone · Safari → Share → Add to Home Screen',1140,26);
            line('Android · Open the menu and tap Install app if offered',1194,25);
            by('card-status').textContent='';print.disabled=save.disabled=false;
        }catch{if(id===version)by('card-status').textContent='Could not load the card. Refresh and try again.';}
    }
    document.querySelectorAll('[data-tab]').forEach(btn=>btn.onclick=()=>{
        document.querySelectorAll('[data-tab]').forEach(b=>{const active=b===btn;b.setAttribute('aria-pressed',String(active));by(b.dataset.tab).hidden=!active;});
        if(btn.dataset.tab==='cards')draw();
    });
    dest.onchange=draw;
    print.onclick=async()=>{
        const paper=document.createElement('img');
        paper.id='print-qr-paper';paper.src=canvas.toDataURL('image/png');
        document.querySelector('#print-qr-paper')?.remove();document.body.appendChild(paper);
        await paper.decode();
        window.addEventListener('afterprint',()=>paper.remove(),{once:true});window.print();
    };
    // Embed the exact rendered card as a single JPEG on a portrait PDF page.
    save.onclick=()=>{
        const jpg=Uint8Array.from(atob(canvas.toDataURL('image/jpeg',0.99).split(',')[1]),c=>c.charCodeAt(0));
        const enc=new TextEncoder(),parts=[],offsets=[0];let length=0;
        function append(chunk){const data=typeof chunk==='string'?enc.encode(chunk):chunk;parts.push(data);length+=data.length;}
        function object(number,content){offsets[number]=length;append(number+' 0 obj\n');content();append('\nendobj\n');}
        append('%PDF-1.4\n');
        object(1,()=>append('<< /Type /Catalog /Pages 2 0 R >>'));
        object(2,()=>append('<< /Type /Pages /Kids [3 0 R] /Count 1 >>'));
        object(3,()=>append('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 450 630] /Resources << /XObject << /Card 4 0 R >> >> /Contents 5 0 R >>'));
        object(4,()=>{append('<< /Type /XObject /Subtype /Image /Width 900 /Height 1260 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '+jpg.length+' >>\nstream\n');append(jpg);append('\nendstream');});
        const commands='q 450 0 0 630 0 0 cm /Card Do Q\n';
        object(5,()=>append('<< /Length '+commands.length+' >>\nstream\n'+commands+'endstream'));
        const xref=length;append('xref\n0 6\n0000000000 65535 f \n');
        offsets.slice(1).forEach(n=>append(String(n).padStart(10,'0')+' 00000 n \n'));
        append('trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF\n');
        const url=URL.createObjectURL(new Blob(parts,{type:'application/pdf'})),a=document.createElement('a');
        a.href=url;a.download=canvas.dataset.name+' - '+dest.selectedOptions[0].text+'.pdf';a.click();setTimeout(()=>URL.revokeObjectURL(url),30000);
    };
})();
