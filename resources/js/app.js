const toggle=document.querySelector('#nav-toggle');const sidebar=document.querySelector('#sidebar');if(toggle&&sidebar){const mobile=window.matchMedia('(max-width: 750px)');const setOpen=open=>{sidebar.classList.toggle('is-open',open);toggle.setAttribute('aria-expanded',String(open));sidebar.toggleAttribute('inert',mobile.matches&&!open);sidebar.setAttribute('aria-hidden',String(mobile.matches&&!open));if(open)sidebar.querySelector('a')?.focus()};const close=()=>{setOpen(false);toggle.focus()};setOpen(false);toggle.addEventListener('click',()=>setOpen(toggle.getAttribute('aria-expanded')!=='true'));document.addEventListener('keydown',event=>{if(event.key==='Escape'&&sidebar.classList.contains('is-open'))close()});mobile.addEventListener('change',()=>setOpen(false))}

const matrix = document.querySelector('[data-permission-matrix]');
if (matrix) {
    const mobile = window.matchMedia('(max-width: 750px)');
    const search = matrix.querySelector('#matrix-search');
    const domain = matrix.querySelector('#matrix-domain');
    const role = matrix.querySelector('#matrix-role');
    const rows = [...matrix.querySelectorAll('[data-matrix-permission]')];
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
    const update = () => {
        const query = normalize(search.value.trim());
        let visible = 0;
        rows.forEach(row => {
            row.hidden = (domain.value && row.dataset.domain !== domain.value) || !normalize(row.dataset.search).includes(query);
            if (!row.hidden) visible++;
        });
        matrix.querySelectorAll('[data-matrix-group]').forEach(group => {
            group.hidden = ![...group.querySelectorAll('[data-matrix-permission]')].some(row => !row.hidden);
        });
        matrix.querySelectorAll('[data-role]').forEach(cell => {
            cell.hidden = mobile.matches && cell.dataset.role !== role.value;
        });
        matrix.querySelector('[data-matrix-empty]').hidden = visible > 0;
        matrix.querySelector('[data-matrix-count]').textContent = `${visible} permission${visible > 1 ? 's' : ''} affichée${visible > 1 ? 's' : ''}`;
    };
    matrix.classList.add('matrix-enhanced');
    matrix.querySelector('[data-matrix-tools]').hidden = false;
    search.addEventListener('input', update);
    domain.addEventListener('change', update);
    role.addEventListener('change', update);
    mobile.addEventListener('change', update);
    matrix.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            checkbox.closest('label').querySelector('[data-matrix-state]').textContent = checkbox.checked ? 'Accordée' : 'Refusée';
        });
    });
    matrix.querySelectorAll('[data-matrix-error]').forEach(link => {
        link.addEventListener('click', () => {
            const control = document.getElementById(link.hash.slice(1));
            if (!control?.closest('[data-role]')) return;
            role.value = control.closest('[data-role]').dataset.role;
            search.value = '';
            domain.value = '';
            update();
            control.focus();
        });
    });
    update();
}
