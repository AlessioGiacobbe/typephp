import panel as pn

pn.extension()

slider = pn.widgets.IntSlider(value=5, start=1, end=10)

def model(n):
    return "⭐" * n

interactive_model = pn.bind(model, n=slider)

layout = pn.Column(slider, interactive_model)

layout.servable()  # For deploying as a web app