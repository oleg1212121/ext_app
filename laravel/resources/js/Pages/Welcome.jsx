import React from 'react';
import Main from '../Layouts/Main.jsx'

const Welcome = () => {
    return (
        <>
            <h1>HELLO</h1>
        </>
    )
}

Welcome.layout = (page) => <Main children={page}/>
export default Welcome;
