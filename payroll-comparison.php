<?php
// Payroll Comparison — included via index.php
$payrolls_res = $conn->query("
    SELECT p.id, p.ref_no, p.date_from, p.date_to, e.employer_name
    FROM payroll p LEFT JOIN employers e ON p.employer_id = e.id
    WHERE p.status >= 1
    ORDER BY p.date_from DESC
");
$payrolls = [];
while ($r = $payrolls_res->fetch_assoc()) $payrolls[] = $r;
?>
<style>
    /* ── Payroll Comparison — brand teal ── */
    .pc-wrap { --pc:#219688; --pc-d:#176358; }
    .pc-selectbar { background:#fff; border:1px solid #e2e8f0; border-top:3px solid var(--pc); border-radius:10px; box-shadow:0 1px 6px rgba(33,150,136,.07); }
    .pc-flabel { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:var(--pc); margin-bottom:4px; display:block; }
    .pc-vs { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--pc),var(--pc-d)); color:#fff; font-weight:800; font-size:12px; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 8px rgba(33,150,136,.35); }
    .pc-btn { background:linear-gradient(135deg,var(--pc),var(--pc-d)); color:#fff; font-weight:700; border:none; }
    .pc-btn:hover { opacity:.92; color:#fff; }

    /* Summary cards */
    .pc-stat { background:#fff; border:1px solid #e9eef5; border-radius:12px; padding:14px 16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 6px rgba(0,0,0,.05); height:100%; }
    .pc-stat .ic { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
    .pc-stat .lbl { font-size:10px; color:#9aa3ad; text-transform:uppercase; letter-spacing:.4px; }
    .pc-stat .val { font-size:15px; font-weight:800; line-height:1.15; }
    .pc-stat .sub { font-size:10px; color:#aaa; margin-top:1px; }

    /* Top movers */
    .pc-mover { background:#fff; border:1px solid #e9eef5; border-radius:12px; padding:13px 15px; box-shadow:0 1px 6px rgba(0,0,0,.05); }
    .pc-mover .mv-h { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:6px; margin-bottom:8px; }
    .pc-mover .mv-row { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:1px dashed #f0f0f0; font-size:12px; }
    .pc-mover .mv-row:last-child { border-bottom:none; }
    .pc-mover .mv-name { font-weight:600; color:#333; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:150px; }

    /* Table */
    #cmp-table { margin:0; }
    #cmp-table thead th { position:sticky; top:0; z-index:5; }
    .cmp-th   { background:var(--pc) !important; color:#fff !important; font-size:10.5px !important; border:none !important; padding:9px 10px !important; font-weight:700; white-space:nowrap; }
    .cmp-th2  { background:var(--pc-d) !important; color:#fff !important; font-size:10.5px !important; border:none !important; padding:9px 10px !important; font-weight:700; white-space:nowrap; }
    #cmp-table td { padding:8px 10px; font-size:12px; vertical-align:middle; }
    #cmp-table tbody tr:hover td { background:#f4fbfa; }
    .cmp-up   { color:#1e9e54; font-weight:700; }
    .cmp-down { color:#dc3545; font-weight:700; }
    .cmp-same { color:#aaa; }
    .pc-emp-av { width:28px; height:28px; border-radius:50%; background:#e6f5f3; color:var(--pc-d); font-size:10px; font-weight:800; display:inline-flex; align-items:center; justify-content:center; margin-right:8px; }
    .pc-tag { font-size:9px; font-weight:800; padding:1px 6px; border-radius:10px; text-transform:uppercase; letter-spacing:.3px; }
    .pc-tag.new { background:#e7f7ee; color:#1e9e54; }
    .pc-tag.gone{ background:#fdecec; color:#dc3545; }
    .pc-pct { font-size:10px; font-weight:700; padding:1px 6px; border-radius:10px; }
    .pc-pct.up { background:#e7f7ee; color:#1e9e54; }
    .pc-pct.down { background:#fdecec; color:#dc3545; }
    .pc-pct.flat { background:#f1f3f5; color:#999; }
    .pc-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; padding:10px 12px; border-bottom:1px solid #eef0f3; }
</style>

<div class="main-content pc-wrap">
<div class="page-content">
<div class="container-fluid">

    <div class="row mb-3">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between bg-galaxy-transparent">
                <h4 class="mb-sm-0"><i class="ri-arrow-left-right-line me-2" style="color:#219688;"></i>Payroll Comparison</h4>
                <ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="javascript:void(0);">Pages</a></li>
                    <li class="breadcrumb-item active">Payroll Comparison</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Period selectors -->
    <div class="pc-selectbar mb-3">
        <div class="p-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="pc-flabel"><i class="ri-calendar-line me-1"></i>Period A</label>
                    <select id="sel-a" class="selectpicker form-control" data-live-search="true" data-size="10" data-width="100%" title="Search payroll…">
                        <?php foreach ($payrolls as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ref_no']) ?> | <?= date('M d', strtotime($p['date_from'])) ?> – <?= date('M d, Y', strtotime($p['date_to'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto d-none d-md-flex justify-content-center pb-1">
                    <div class="pc-vs">VS</div>
                </div>
                <div class="col-md-5">
                    <label class="pc-flabel"><i class="ri-calendar-line me-1"></i>Period B</label>
                    <select id="sel-b" class="selectpicker form-control" data-live-search="true" data-size="10" data-width="100%" title="Search payroll…">
                        <?php foreach ($payrolls as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ref_no']) ?> | <?= date('M d', strtotime($p['date_from'])) ?> – <?= date('M d, Y', strtotime($p['date_to'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-auto">
                    <button onclick="loadComparison()" class="btn btn-sm pc-btn w-100">
                        <i class="ri-arrow-left-right-line me-1"></i>Compare
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary strip -->
    <div id="cmp-summary" style="display:none;" class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="pc-stat">
                <div class="ic" style="background:#e6f5f3;color:#219688;"><i class="ri-group-line"></i></div>
                <div><div class="lbl">Employees A / B</div><div class="val" id="s-emp" style="color:#219688;"></div><div class="sub" id="s-emp-sub"></div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="pc-stat">
                <div class="ic" style="background:#eef0f8;color:#6f42c1;"><i class="ri-wallet-3-line"></i></div>
                <div><div class="lbl">Net Pay A / B</div><div class="val" id="s-net" style="color:#6f42c1;"></div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="pc-stat">
                <div class="ic" style="background:#e7f7ee;color:#1e9e54;"><i class="ri-money-dollar-circle-line"></i></div>
                <div><div class="lbl">Gross A / B</div><div class="val" id="s-gross" style="color:#1e9e54;"></div></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="pc-stat">
                <div class="ic" id="s-diff-ic" style="background:#fdecec;color:#dc3545;"><i class="ri-arrow-up-down-line"></i></div>
                <div><div class="lbl">Net Difference (B−A)</div><div class="val" id="s-diff"></div><div class="sub" id="s-diff-pct"></div></div>
            </div>
        </div>
    </div>

    <!-- Top movers -->
    <div id="cmp-movers" style="display:none;" class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="pc-mover">
                <div class="mv-h" style="color:#1e9e54;"><i class="ri-arrow-up-circle-fill"></i> Top Gainers (Net ↑)</div>
                <div id="mv-up"></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="pc-mover">
                <div class="mv-h" style="color:#dc3545;"><i class="ri-arrow-down-circle-fill"></i> Top Decreases (Net ↓)</div>
                <div id="mv-down"></div>
            </div>
        </div>
    </div>

    <!-- Results table -->
    <div class="card">
        <div class="pc-toolbar" id="cmp-tools" style="display:none;">
            <span style="font-size:11px;color:#888;"><span id="cmp-count">0</span> employees</span>
            <select id="cmp-emp" class="form-select form-select-sm" style="max-width:240px;">
                <option value="">All employees</option>
            </select>
            <select id="cmp-mode" class="form-select form-select-sm" style="max-width:170px;">
                <option value="all">Show all</option>
                <option value="changed">Only changed</option>
                <option value="up">Only increases</option>
                <option value="down">Only decreases</option>
                <option value="new">New / removed</option>
            </select>
            <input type="text" id="cmp-search" class="form-control form-control-sm" placeholder="Search employee…" style="max-width:200px;margin-left:auto;">
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:60vh;">
                <table class="table table-hover table-sm align-middle mb-0" id="cmp-table">
                    <thead id="cmp-head"></thead>
                    <tbody id="cmp-body">
                        <tr><td colspan="10" class="text-center py-5" style="color:#bbb;">
                            <i class="ri-arrow-left-right-line" style="font-size:38px;display:block;margin-bottom:8px;opacity:.5;"></i>
                            Select two payroll periods above and click <b>Compare</b>
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div></div></div>

<script>
function fmt(v){ return '₱'+parseFloat(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmt0(v){ return '₱'+parseFloat(v||0).toLocaleString('en-PH',{maximumFractionDigits:0}); }
function initials(name){ var p=(name||'').replace(',',' ').trim().split(/\s+/); return ((p[0]||'')[0]||'').toUpperCase()+((p[1]||'')[0]||'').toUpperCase(); }
function pctBadge(b,a){
    a=parseFloat(a||0); b=parseFloat(b||0);
    if(a===0 && b===0) return '<span class="pc-pct flat">—</span>';
    if(a===0) return '<span class="pc-pct up">NEW</span>';
    var p=((b-a)/a)*100;
    var cls=p>0.05?'up':p<-0.05?'down':'flat';
    var s=(p>0?'+':'')+p.toFixed(1)+'%';
    return '<span class="pc-pct '+cls+'">'+s+'</span>';
}

var CMP = { rows: [] };

function loadComparison(){
    var idA=document.getElementById('sel-a').value;
    var idB=document.getElementById('sel-b').value;
    if(!idA||!idB){ Swal.fire({icon:'info',title:'Select both periods',timer:1600,showConfirmButton:false}); return; }
    if(idA===idB){ Swal.fire({icon:'info',title:'Pick two different periods',timer:1600,showConfirmButton:false}); return; }

    document.getElementById('cmp-body').innerHTML='<tr><td colspan="10" class="text-center py-4"><div class="spinner-border spinner-border-sm text-success"></div> Loading…</td></tr>';

    fetch('ajax.php?action=compare_payrolls',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'id_a='+idA+'&id_b='+idB
    })
    .then(r=>r.json())
    .then(function(data){
        if(!data.result){ document.getElementById('cmp-body').innerHTML='<tr><td colspan="10" class="text-center py-4 text-danger">'+(data.message||'Error')+'</td></tr>'; return; }
        CMP.rows = data.rows; CMP.labelA = data.label_a; CMP.labelB = data.label_b;
        renderHead();
        renderEmployeeFilter();
        renderBody();
        renderSummary();
        renderMovers();
        document.getElementById('cmp-tools').style.display='flex';
    });
}

function renderHead(){
    document.getElementById('cmp-head').innerHTML=
        '<tr>'+
        '<th class="cmp-th" rowspan="2">#</th>'+
        '<th class="cmp-th" rowspan="2">Employee</th>'+
        '<th class="cmp-th" colspan="3" style="text-align:center;border-left:2px solid #176358 !important;">'+CMP.labelA+'</th>'+
        '<th class="cmp-th2" colspan="3" style="text-align:center;border-left:2px solid #0d3d35 !important;">'+CMP.labelB+'</th>'+
        '<th class="cmp-th" rowspan="2" style="text-align:right;">Net Diff</th>'+
        '<th class="cmp-th" rowspan="2" style="text-align:center;">% </th>'+
        '</tr>'+
        '<tr>'+
        '<th class="cmp-th" style="text-align:right;">Basic</th><th class="cmp-th" style="text-align:right;">Gross</th><th class="cmp-th" style="text-align:right;">Net</th>'+
        '<th class="cmp-th2" style="text-align:right;border-left:2px solid #0d3d35 !important;">Basic</th><th class="cmp-th2" style="text-align:right;">Gross</th><th class="cmp-th2" style="text-align:right;">Net</th>'+
        '</tr>';
}

function rowState(r){
    var inA=r.a!=null, inB=r.b!=null;
    if(!inA && inB) return 'new';
    if(inA && !inB) return 'gone';
    var nd=parseFloat((r.b&&r.b.net)||0)-parseFloat((r.a&&r.a.net)||0);
    return nd>0.005?'up':nd<-0.005?'down':'flat';
}

function renderBody(){
    var html='', i=0;
    var totA={basic:0,gross:0,net:0,emp:0}, totB={basic:0,gross:0,net:0,emp:0};
    CMP.rows.forEach(function(r){
        i++;
        var inA=r.a!=null, inB=r.b!=null;
        if(inA){ totA.basic+=+r.a.basic||0; totA.gross+=+r.a.gross||0; totA.net+=+r.a.net||0; totA.emp++; }
        if(inB){ totB.basic+=+r.b.basic||0; totB.gross+=+r.b.gross||0; totB.net+=+r.b.net||0; totB.emp++; }
        var netDiff=parseFloat(inB?(r.b.net||0):0)-parseFloat(inA?(r.a.net||0):0);
        var diffCls=netDiff>0.005?'cmp-up':netDiff<-0.005?'cmp-down':'cmp-same';
        var st=rowState(r);
        var tag = st==='new'?'<span class="pc-tag new">New</span>':st==='gone'?'<span class="pc-tag gone">Removed</span>':'';
        html+='<tr data-state="'+st+'" data-emp="'+r.employee_id+'">'+
            '<td style="color:#aaa;">'+i+'</td>'+
            '<td><span class="pc-emp-av">'+initials(r.name)+'</span><span style="font-weight:700;">'+r.name+'</span> '+tag+'<div style="font-size:10px;color:#aaa;margin-left:36px;">'+r.employee_no+'</div></td>'+
            '<td style="text-align:right;">'+(inA?fmt(r.a.basic):'<span style="color:#ddd;">—</span>')+'</td>'+
            '<td style="text-align:right;">'+(inA?fmt(r.a.gross):'<span style="color:#ddd;">—</span>')+'</td>'+
            '<td style="text-align:right;font-weight:700;border-right:2px solid #e9ecef;">'+(inA?fmt(r.a.net):'<span style="color:#ddd;">—</span>')+'</td>'+
            '<td style="text-align:right;border-left:2px solid #e9ecef;">'+(inB?fmt(r.b.basic):'<span style="color:#ddd;">—</span>')+'</td>'+
            '<td style="text-align:right;">'+(inB?fmt(r.b.gross):'<span style="color:#ddd;">—</span>')+'</td>'+
            '<td style="text-align:right;font-weight:700;">'+(inB?fmt(r.b.net):'<span style="color:#ddd;">—</span>')+'</td>'+
            '<td style="text-align:right;"><span class="'+diffCls+'">'+(netDiff>0?'+':'')+fmt(netDiff)+'</span></td>'+
            '<td style="text-align:center;">'+pctBadge(inB?r.b.net:0, inA?r.a.net:0)+'</td>'+
            '</tr>';
    });
    html+='<tr style="background:#eef6f4;font-weight:800;border-top:2px solid #cde7e2;">'+
        '<td colspan="2" style="color:#176358;padding:10px 12px;">TOTAL ('+CMP.rows.length+')</td>'+
        '<td style="text-align:right;">'+fmt(totA.basic)+'</td><td style="text-align:right;">'+fmt(totA.gross)+'</td>'+
        '<td style="text-align:right;border-right:2px solid #cde7e2;">'+fmt(totA.net)+'</td>'+
        '<td style="text-align:right;border-left:2px solid #cde7e2;">'+fmt(totB.basic)+'</td><td style="text-align:right;">'+fmt(totB.gross)+'</td>'+
        '<td style="text-align:right;">'+fmt(totB.net)+'</td>'+
        '<td style="text-align:right;" colspan="2">'+(function(){var d=totB.net-totA.net;var c=d>0?'cmp-up':d<0?'cmp-down':'cmp-same';return '<span class="'+c+'">'+(d>0?'+':'')+fmt(d)+'</span>';})()+'</td>'+
        '</tr>';
    document.getElementById('cmp-body').innerHTML=html;
    CMP.totA=totA; CMP.totB=totB;
    applyFilters();
}

function renderSummary(){
    var A=CMP.totA, B=CMP.totB, nd=B.net-A.net;
    document.getElementById('s-emp').textContent=A.emp+' / '+B.emp;
    document.getElementById('s-emp-sub').textContent=(B.emp-A.emp>=0?'+':'')+(B.emp-A.emp)+' employees';
    document.getElementById('s-net').textContent=fmt0(A.net)+' / '+fmt0(B.net);
    document.getElementById('s-gross').textContent=fmt0(A.gross)+' / '+fmt0(B.gross);
    var se=document.getElementById('s-diff');
    se.textContent=(nd>0?'+':'')+fmt(nd);
    se.style.color=nd>0?'#1e9e54':nd<0?'#dc3545':'#888';
    document.getElementById('s-diff-pct').innerHTML=pctBadge(B.net,A.net)+' vs Period A';
    var ic=document.getElementById('s-diff-ic');
    if(nd>0){ ic.style.background='#e7f7ee'; ic.style.color='#1e9e54'; ic.innerHTML='<i class="ri-arrow-up-line"></i>'; }
    else if(nd<0){ ic.style.background='#fdecec'; ic.style.color='#dc3545'; ic.innerHTML='<i class="ri-arrow-down-line"></i>'; }
    else { ic.style.background='#f1f3f5'; ic.style.color='#888'; ic.innerHTML='<i class="ri-subtract-line"></i>'; }
    document.getElementById('cmp-summary').style.display='';
}

function renderMovers(){
    var moved=CMP.rows.filter(function(r){ return r.a!=null && r.b!=null; })
        .map(function(r){ return { name:r.name, no:r.employee_no, d:(+r.b.net||0)-(+r.a.net||0) }; });
    var up=moved.filter(m=>m.d>0).sort((x,y)=>y.d-x.d).slice(0,5);
    var down=moved.filter(m=>m.d<0).sort((x,y)=>x.d-y.d).slice(0,5);
    function rowsHtml(list, cls){
        if(!list.length) return '<div style="font-size:11px;color:#bbb;padding:6px 0;">None</div>';
        return list.map(function(m){
            return '<div class="mv-row"><span class="mv-name">'+m.name+'</span><span class="'+cls+'">'+(m.d>0?'+':'')+fmt(m.d)+'</span></div>';
        }).join('');
    }
    document.getElementById('mv-up').innerHTML=rowsHtml(up,'cmp-up');
    document.getElementById('mv-down').innerHTML=rowsHtml(down,'cmp-down');
    document.getElementById('cmp-movers').style.display='';
}

function renderEmployeeFilter(){
    var sel=document.getElementById('cmp-emp');
    var opts='<option value="">All employees</option>';
    CMP.rows.slice().sort((a,b)=>a.name.localeCompare(b.name)).forEach(function(r){
        opts+='<option value="'+r.employee_id+'">'+r.name+'</option>';
    });
    sel.innerHTML=opts;
}

function applyFilters(){
    var emp=document.getElementById('cmp-emp').value;
    var mode=document.getElementById('cmp-mode').value;
    var q=(document.getElementById('cmp-search').value||'').toLowerCase();
    var shown=0;
    document.querySelectorAll('#cmp-body tr[data-emp]').forEach(function(tr){
        var st=tr.getAttribute('data-state');
        var ok=true;
        if(emp && tr.getAttribute('data-emp')!==emp) ok=false;
        if(ok && mode==='changed' && (st==='flat')) ok=false;
        if(ok && mode==='up' && st!=='up') ok=false;
        if(ok && mode==='down' && st!=='down') ok=false;
        if(ok && mode==='new' && st!=='new' && st!=='gone') ok=false;
        if(ok && q && !tr.textContent.toLowerCase().includes(q)) ok=false;
        tr.style.display=ok?'':'none';
        if(ok) shown++;
    });
    document.getElementById('cmp-count').textContent=shown;
}

['cmp-emp','cmp-mode'].forEach(function(id){ document.addEventListener('change',function(e){ if(e.target.id===id) applyFilters(); }); });
document.getElementById('cmp-search').addEventListener('input', applyFilters);

// Searchable bootstrap-select for the two period pickers
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.selectpicker) {
        jQuery('#sel-a, #sel-b').selectpicker();
    }
});
</script>
