import React from 'react';
import {X,Search,LoaderCircle,Inbox,CheckCircle2,AlertCircle} from 'lucide-react';
export let csrfToken='';
export function setCsrf(value){csrfToken=value||'';}
export async function api(path,options={}){
 const form=options.body instanceof FormData;const response=await fetch('/api'+path,{...options,credentials:'same-origin',headers:{...(options.body&&!form?{'Content-Type':'application/json'}:{}),...(options.method&&options.method!=='GET'?{'X-CSRF-Token':csrfToken}:{}),...options.headers},body:options.body?(form?options.body:JSON.stringify(options.body)):undefined});
 let value;try{value=await response.json()}catch{throw new Error('Сервер не ответил. Попробуйте ещё раз.');}
 if(!response.ok){if(response.status===401&&path!=='/auth/login')window.dispatchEvent(new Event('ursar-unauthorized'));const error=new Error(value.error||'Не удалось выполнить действие.');error.status=response.status;throw error;}return value;
}
export function useLoad(path,deps=[]){
 const [data,setData]=React.useState(null),[error,setError]=React.useState(''),[loading,setLoading]=React.useState(true),[revision,reload]=React.useReducer(x=>x+1,0);
 React.useEffect(()=>{let active=true;setLoading(true);setError('');api(path).then(v=>{if(active)setData(v)}).catch(e=>{if(active)setError(e.message)}).finally(()=>{if(active)setLoading(false)});return()=>{active=false}},[path,revision,...deps]);return {data,setData,error,loading,reload};
}
export function Loading(){return <div className="loading" role="status"><LoaderCircle className="spin" size={24}/> Загружаем…</div>}
export function ErrorBox({children}){return children?<div className="notice danger" role="alert"><AlertCircle size={18}/>{children}</div>:null}
export function Empty({title='Ничего не найдено',text='Измените запрос или добавьте первый элемент.',icon:Icon=Inbox,children}){return <div className="empty"><div className="empty-icon"><Icon size={30}/></div><h3>{title}</h3><p>{text}</p>{children}</div>}
export function Heading({title,description,children}){return <div className="page-heading"><div><p className="eyebrow">URSAR · УПРАВЛЕНИЕ САЙТОМ</p><h1>{title}</h1>{description&&<p>{description}</p>}</div><div className="actions">{children}</div></div>}
export function Field({label,hint,children,...props}){return <label className="field"><span>{label}</span>{children||<input {...props}/>} {hint&&<small>{hint}</small>}</label>}
export function SearchBox({value,onChange,placeholder='Поиск…'}){return <label className="search-box"><Search size={18}/><input aria-label={placeholder} placeholder={placeholder} value={value} onChange={e=>onChange(e.target.value)}/>{value&&<button className="icon-button" aria-label="Очистить поиск" onClick={()=>onChange('')}><X size={16}/></button>}</label>}
export function Modal({title,children,onClose,wide=false}){const ref=React.useRef(null);React.useEffect(()=>{const d=ref.current;d.showModal();return()=>d.close()},[]);return <dialog ref={ref} className={'modal '+(wide?'wide':'')} onCancel={e=>{e.preventDefault();onClose()}} onClick={e=>{if(e.target===ref.current)onClose()}}><div className="modal-heading"><h2>{title}</h2><button className="icon-button" onClick={onClose} aria-label="Закрыть"><X size={22}/></button></div><div className="modal-body">{children}</div></dialog>}
export function Pill({item}){return <span className={'pill '+(item.archived?'gray':item.hasDraft?'amber':'')}>{item.archived?'В архиве':!item.isPublished?'Черновик':item.hasDraft?'Есть изменения':'Опубликовано'}</span>}
export function Toast({value}){return value?<div className={'toast '+(value.error?'bad':'')} role="status">{value.error?<AlertCircle size={20}/>:<CheckCircle2 size={20}/>} {value.text}</div>:null}
export const date=value=>value?new Intl.DateTimeFormat('ru',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}).format(new Date(value)):'—';
export const categories={'lawn-mower':'Газонокосилки','skid-loader':'Мини-погрузчики'};
export const roles={admin:'Администратор',editor:'Редактор',manager:'Менеджер обращений'};
export function Pagination({page,total,size=48,onChange}){const pages=Math.ceil(total/size);return pages>1?<div className="pagination"><button className="button secondary small" disabled={page===1} onClick={()=>onChange(page-1)}>Назад</button><span>{page} / {pages} · Всего {total}</span><button className="button secondary small" disabled={page>=pages} onClick={()=>onChange(page+1)}>Далее</button></div>:null}
